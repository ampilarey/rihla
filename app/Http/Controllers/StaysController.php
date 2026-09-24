<?php

namespace App\Http\Controllers;

use App\Exceptions\RoomNotAvailable;
use App\Models\Package;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\Stays\Availability;
use App\Services\Stays\GreenTax;
use App\Services\Stays\ShareCard;
use App\Support\Contact;
use App\Support\Services as ServiceRegistry;
use App\Support\StayFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public Stays pages — §15.4 (Phase 9.4).
 *
 * ## Two switches, not one
 *
 * `EnsureServiceEnabled` has already turned away anyone an `off` switch
 * says should not be here, and shares which live state they arrived under.
 * `coming_soon` means §15.2 decision 6: *show the pages, take enquiries,
 * take no money*. So every listing here asks two questions — is the service
 * `on`, and is there anything published — and a no to either shows the
 * enquiry page rather than an empty list.
 *
 * That is not the same as hiding a broken feature. A guesthouse line with
 * nothing published yet is genuinely an enquiry business, and the page that
 * says so and takes a phone number is the honest one.
 *
 * ## Availability is asked, not joined
 *
 * The list checks dates through {@see Availability} — the same class the
 * booking path uses — rather than reproducing the logic in SQL. A
 * correlated subquery per night would be faster and would eventually
 * disagree with the booking path about whether somewhere is free, which is
 * the one disagreement this line cannot afford.
 */
class StaysController extends Controller
{
    public function __construct(
        private readonly Availability $availability,
        private readonly GreenTax $greenTax,
    ) {}

    /**
     * The Stays hub.
     *
     * 404s when every strand is off, rather than rendering a page whose
     * every link is missing. Not gated by the `service` middleware, because
     * no single service owns it.
     */
    public function index(): View
    {
        $strands = collect(ServiceRegistry::catalogue())
            ->reject(fn (array $meta, string $key): bool => ServiceRegistry::isOff($key));

        if ($strands->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return view('stays.index', [
            'strands' => $strands,
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    public function guesthouses(Request $request): View
    {
        return $this->strand($request, 'stays_guesthouses', Property::GUESTHOUSE, __(
            'messages.Rihla markets a hand-picked set of guesthouses across the Maldives on behalf of the people who run them. Tell us where and when you are thinking of, and we will send you what is available.',
        ));
    }

    /**
     * Island holidays run on the **package** engine, not the stays one —
     * §15.5 (Phase 10). They are filed here because they are a guesthouse
     * product, which is the owner's correction in §15.1, and they are
     * listed from `packages` because that is where they live.
     */
    public function islandHolidays(Request $request): View
    {
        $blurb = __('messages.Short island holidays for Maldivian families — a weekend away, arranged the way our Umrah groups already are. Tell us which island and when, and we will put together a plan.');

        $holidays = Package::published()
            ->ofType([Package::ISLAND_HOLIDAY])
            ->with(['publishedDepartures' => fn ($query) => $query->upcoming()->with('priceTiers'), 'property'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($holidays->isEmpty()) {
            return $this->comingSoon('stays_island_holidays', $blurb);
        }

        return view('stays.island-holidays', [
            'label' => __(ServiceRegistry::catalogue()['stays_island_holidays']['label']),
            'blurb' => $blurb,
            'holidays' => $holidays,
            'bookable' => ServiceRegistry::isOn('stays_island_holidays'),
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    public function rooms(Request $request): View
    {
        return $this->strand($request, 'stays_rooms', Property::RENTAL, __(
            'messages.Nightly rooms in Malé, booked and paid for online. Tell us your dates and we will let you know as soon as booking opens.',
        ));
    }

    /**
     * One property.
     *
     * Route-model bound by slug, so an unpublished one 404s here rather
     * than at a check further down: a draft guesthouse must not be readable
     * by anybody who guesses its name.
     */
    public function show(Request $request, Property $property): View
    {
        abort_unless($property->is_published, 404);

        $service = $property->type === Property::RENTAL ? 'stays_rooms' : 'stays_guesthouses';

        if (ServiceRegistry::isOff($service)) {
            throw new NotFoundHttpException;
        }

        $filters = StayFilters::fromRequest($request);

        $property->load(['roomTypes', 'partner']);

        // §15.2 decision 5. The estimate only when the reader has actually
        // said how many of them there are and for which nights — a figure
        // computed from a default party size would be a number nobody
        // asked for, presented as though they had.
        $guests = $filters->guests;
        $nights = $filters->hasDates()
            ? (int) $filters->checkIn->diffInDays($filters->checkOut)
            : 0;

        return view('stays.show', [
            'property' => $property,
            'filters' => $filters,
            'greenTaxAtProperty' => $this->greenTax->isCollectedAtProperty($property),
            'greenTaxRate' => $this->greenTax->perGuestPerNight(),
            'greenTaxEstimate' => $guests !== null && $nights > 0
                ? $this->greenTax->forParty($guests, $nights)
                : null,
            'rooms' => $this->priceRooms($property, $filters),
            'bookable' => ServiceRegistry::isOn($service),
            'shareCard' => $this->shareCardUrl($property),
            'hasFactSheet' => in_array(app()->getLocale(), self::SHEET_LOCALES, true),
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    /**
     * The absolute URL an OG tag points at.
     *
     * Versioned by the cover's own fingerprint, so replacing a photograph
     * produces a URL no scraper has cached. The brand image when there is
     * no cover to crop — a generic preview beats a broken one.
     */
    private function shareCardUrl(Property $property): string
    {
        $cards = app(ShareCard::class);
        $version = $cards->isSupported() ? $cards->version($property) : null;

        return $version === null
            ? asset('images/rihla-social.png')
            : route('stays.card', ['property' => $property->slug]).'?v='.$version;
    }

    /**
     * The 1200×630 preview a pasted link shows — §15.4 (Phase 9.5).
     *
     * Streamed rather than redirected to storage, so the OG tag points at a
     * URL on this domain that is always answerable. Cached hard *because
     * the URL carries a content hash*: replacing a cover produces a
     * different URL, so a long cache cannot serve last year's photograph —
     * the trap `AGENTS.md` records for the service worker.
     *
     * Falls through to the brand image when there is no cover or no GD. A
     * plain preview is a smaller problem than a 500 on a page somebody is
     * trying to share.
     */
    public function shareCard(Property $property, ShareCard $cards): Response|RedirectResponse
    {
        abort_unless($property->is_published, 404);

        $png = $cards->bytes($property);

        if ($png === null) {
            return redirect()->away(asset('images/rihla-social.png'));
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Locales whose script this PDF can actually be printed in.
     *
     * **Arabic is absent, and measured rather than assumed.** dompdf
     * reverses an RTL run but applies no contextual shaping — a rendered
     * Arabic sheet extracts as 21 base letters and 0 presentation forms,
     * which on the page means every letter in its isolated form, joined to
     * nothing. To an Arabic reader that is not merely ugly; it is visibly
     * broken, on a document meant to be forwarded to a customer.
     *
     * Thaana does not join, so Dhivehi is unaffected and prints correctly
     * with A_Faruma — the font the guide's boxes taught this codebase to
     * declare explicitly.
     *
     * The Arabic *page* renders perfectly in any browser, and that is the
     * shareable artefact for an Arabic reader until somebody adds a shaper.
     * Offering a sheet that prints wrongly would be worse than offering
     * none: nobody checks a PDF they forwarded.
     *
     * @var list<string>
     */
    public const SHEET_LOCALES = ['en', 'dv'];

    /**
     * The one-page fact sheet, in the language the URL asked for.
     *
     * The other half of the share kit: a link opens the page, this is what
     * gets attached when somebody wants the details in their hand, on a
     * ferry with no signal, or forwarded to whoever is actually paying.
     */
    public function factSheet(Property $property): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($property->is_published, 404);

        $service = $property->type === Property::RENTAL ? 'stays_rooms' : 'stays_guesthouses';

        if (ServiceRegistry::isOff($service)) {
            throw new NotFoundHttpException;
        }

        $locale = app()->getLocale();

        if (! in_array($locale, self::SHEET_LOCALES, true)) {
            throw new NotFoundHttpException;
        }

        $property->load(['roomTypes', 'partner']);

        $pdf = Pdf::loadView('pdf.property', [
            'property' => $property,
            'rooms' => $property->roomTypes,
            'locale' => $locale,
            'url' => route('stays.show', ['property' => $property->slug]),
            'cover' => $this->coverForPdf($property),
            // §15.2 decision 5, on the artefact that actually gets
            // forwarded. The sheet is what somebody sends to whoever is
            // paying, so the tax they will be asked for belongs on it —
            // no party or dates here, so it carries the rate and not a
            // total.
            'greenTaxAtProperty' => $this->greenTax->isCollectedAtProperty($property),
            'greenTaxRate' => $this->greenTax->perGuestPerNight(),
            'issuer' => [
                'name' => (string) config('invoices.issuer.name'),
                'registration' => config('invoices.issuer.registration'),
                'phone' => Contact::displayNumber(),
            ],
        ]);

        return $pdf->download($property->slug.'-'.$locale.'.pdf');
    }

    /**
     * An absolute filesystem path, not a URL.
     *
     * dompdf fetches a remote image only when `isRemoteEnabled` is on, and
     * on this host it is not — a URL renders as a broken-image box in a
     * document somebody is about to forward. Null when there is no cover,
     * which the template treats as "no picture" rather than an empty frame.
     */
    private function coverForPdf(Property $property): ?string
    {
        if (blank($property->cover_image)) {
            return null;
        }

        $path = Storage::disk('public')->path((string) $property->cover_image);

        return is_file($path) ? $path : null;
    }

    /**
     * A strand's listing, or its enquiry page when there is nothing to list.
     *
     * @param  Property::GUESTHOUSE|Property::RENTAL  $type
     */
    private function strand(Request $request, string $service, string $type, string $blurb): View
    {
        $filters = StayFilters::fromRequest($request);

        $properties = Property::published()
            ->ofType([$type])
            ->with(['roomTypes'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($properties->isEmpty()) {
            return $this->comingSoon($service, $blurb);
        }

        $shown = $filters->apply(
            Property::published()->ofType([$type])->with(['roomTypes'])
        )->orderBy('sort_order')->orderBy('id')->get();

        if ($filters->hasDates()) {
            $shown = $shown->filter(fn (Property $property): bool => $this->hasAnythingFree($property, $filters));
        }

        return view('stays.strand', [
            'label' => __(ServiceRegistry::catalogue()[$service]['label']),
            'blurb' => $blurb,
            'properties' => $shown->values(),
            'filters' => $filters,
            'islands' => $properties->pluck('island')->filter()->unique()->sort()->values(),
            'bookable' => ServiceRegistry::isOn($service),
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    private function comingSoon(string $service, string $blurb): View
    {
        return view('pages.stays-coming-soon', [
            'label' => __(ServiceRegistry::catalogue()[$service]['label']),
            'blurb' => $blurb,
        ]);
    }

    private function hasAnythingFree(Property $property, StayFilters $filters): bool
    {
        return $property->roomTypes->contains(
            fn (RoomType $room): bool => $this->availability->isAvailable(
                $room,
                $filters->checkIn,
                $filters->checkOut,
            ),
        );
    }

    /**
     * Each room, priced for the chosen dates when there are any.
     *
     * With no dates there is no availability question to answer and no
     * total to quote, so the card shows the room's own nightly rate and
     * says what it is. Inventing a price for "some dates" is how a visitor
     * arrives at checkout expecting a number nobody offered.
     *
     * @return list<array<string, mixed>>
     */
    private function priceRooms(Property $property, StayFilters $filters): array
    {
        return $property->roomTypes->map(function (RoomType $room) use ($filters): array {
            if (! $filters->hasDates()) {
                return [
                    'room' => $room,
                    'quote' => null,
                    'available' => null,
                    'reason' => null,
                ];
            }

            $available = true;
            $reason = null;

            try {
                $this->availability->assertAvailable($room, $filters->checkIn, $filters->checkOut);
            } catch (RoomNotAvailable $refusal) {
                $available = false;
                $reason = $refusal->getMessage();
            }

            return [
                'room' => $room,
                'quote' => $this->availability->quote($room, $filters->checkIn, $filters->checkOut),
                'available' => $available,
                'reason' => $reason,
            ];
        })->all();
    }
}
