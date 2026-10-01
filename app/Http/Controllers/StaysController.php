<?php

namespace App\Http\Controllers;

use App\Exceptions\NotSoldToAudience;
use App\Exceptions\RoomNotAvailable;
use App\Models\Package;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\Stays\Availability;
use App\Services\Stays\GreenTax;
use App\Services\Stays\ShareCard;
use App\Services\Stays\StayAddons;
use App\Support\Audience;
use App\Support\Contact;
use App\Support\Money;
use App\Support\Services as ServiceRegistry;
use App\Support\StayFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

    /** A page of search results — §16.7. */
    public const PER_PAGE = 24;

    /** Which door sells each kind of building. Island holidays are packages, not properties. */
    private const DOORS = [
        Property::GUESTHOUSE => 'stays_guesthouses',
        Property::RENTAL => 'stays_rooms',
    ];

    /**
     * The Stays hub, and since §16 the search over every listing.
     *
     * The strand cards stay at the top — they are still the three doors —
     * and every listing behind a door that is not off is searchable below
     * them. 404s when every strand is off, rather than rendering a page
     * whose every link is missing. Not gated by the `service` middleware,
     * because no single service owns it.
     *
     * ## One list, filtered in PHP
     *
     * Dates are checked through {@see Availability}, as on the strand
     * pages, and a price is compared in the audience's own currency, so
     * both run over the loaded rows and the page is cut after. At the
     * size this marketplace will be for years — dozens of listings, not
     * tens of thousands — that is one query with its eager loads, and it
     * is the only way the count on the page and the rows on it agree.
     */
    public function index(Request $request): View
    {
        $strands = collect(ServiceRegistry::catalogue())
            ->reject(fn (array $meta, string $key): bool => ServiceRegistry::isOff($key));

        if ($strands->isEmpty()) {
            throw new NotFoundHttpException;
        }

        $filters = StayFilters::fromRequest($request);

        $types = self::openTypes();

        $listable = $types === []
            ? (new Property)->newCollection()
            : Property::listable()->ofType($types)->offering($filters->audience)
                ->with(['roomTypes', 'partner.page'])
                ->get();

        $found = $filters->apply(
            Property::query()->whereIn('id', $listable->modelKeys())
        )->with(['roomTypes', 'partner.page'])->withRating()->get();

        if ($filters->hasDates()) {
            $found = $found->filter(fn (Property $property): bool => $this->hasAnythingFree($property, $filters));
        }

        if ($filters->hasPriceRange()) {
            $found = $found->filter(fn (Property $property): bool => $this->withinPrice($property, $filters));
        }

        $found = $this->sorted($found, $filters)->values();

        $page = LengthAwarePaginator::resolveCurrentPage();

        $results = (new LengthAwarePaginator(
            $found->forPage($page, self::PER_PAGE)->values(),
            $found->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        ));

        return view('stays.index', [
            'strands' => $strands,
            'filters' => $filters,
            'results' => $results,
            'hasListings' => $listable->isNotEmpty(),
            'islands' => $listable->pluck('island')->filter()->unique()->sort()->values(),
            'atolls' => $listable->pluck('atoll')->filter()->unique()->sort()->values(),
            'kinds' => $listable->pluck('kind')->filter()->unique()->values(),
            'priceCurrency' => $this->priceCurrency($filters->audience),
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    /**
     * The kinds of building whose door is not off.
     *
     * @return list<string>
     */
    public static function openTypes(): array
    {
        return array_keys(array_filter(
            self::DOORS,
            fn (string $service): bool => ! ServiceRegistry::isOff($service),
        ));
    }

    /**
     * Browse by atoll — §16 Phase 15.
     *
     * Every atoll with something listed in it, how many places and on
     * which islands, each a link into the search with the atoll already
     * chosen. Counted for the same audience the search would default to,
     * so the number on the card is the number of results the link opens.
     * A listing with no atoll recorded is left off rather than filed under
     * a guess.
     */
    public function atolls(Request $request): View
    {
        $types = self::openTypes();
        abort_if($types === [], 404);

        $filters = StayFilters::fromRequest($request);

        $atolls = Property::listable()->ofType($types)->offering($filters->audience)
            ->whereNotNull('atoll')
            ->where('atoll', '!=', '')
            ->get(['id', 'atoll', 'island'])
            ->groupBy('atoll')
            ->map(fn (Collection $properties, string $atoll): array => [
                'name' => $atoll,
                'count' => $properties->count(),
                'islands' => $properties->pluck('island')->filter()->unique()->sort()->values()->all(),
                'url' => route('stays.index', array_filter([
                    'atoll' => $atoll,
                    'audience' => $request->filled('audience') ? $filters->audience : null,
                ])),
            ])
            ->sortKeys()
            ->values();

        return view('stays.atolls', ['atolls' => $atolls]);
    }

    /**
     * The currency a price range is typed in.
     *
     * A local's is rufiyaa everywhere. A tourist's is whatever the guest
     * house quotes in, which differs between a guesthouse (dollars) and a
     * Malé room (rufiyaa) — nothing is converted (§16.2 rule 5) — so the
     * range is read in the currency most tourist listings use, and a
     * listing priced in another is left out of a priced search rather than
     * compared across currencies.
     */
    private function priceCurrency(string $audience): string
    {
        return $audience === Audience::LOCAL
            ? strtoupper((string) config('marketplace.currencies.local', 'MVR'))
            : strtoupper((string) config('marketplace.currencies.tourist', 'USD'));
    }

    private function withinPrice(Property $property, StayFilters $filters): bool
    {
        $from = $property->cheapestRateFor($filters->audience);

        if ($from === null || $from->currency !== $this->priceCurrency($filters->audience)) {
            return false;
        }

        $whole = intdiv($from->minor, 100);

        return ($filters->priceMin === null || $whole >= $filters->priceMin)
            && ($filters->priceMax === null || $whole <= $filters->priceMax);
    }

    /**
     * @param  Collection<int, Property>  $found
     * @return Collection<int, Property>
     */
    private function sorted(Collection $found, StayFilters $filters): Collection
    {
        return match ($filters->sort) {
            // Unpriced listings last, not first: "cheapest" that opens on a
            // card with no price is not an answer to the question.
            'price' => $found->sortBy(fn (Property $property): int => $property->cheapestRateFor($filters->audience)->minor ?? PHP_INT_MAX),
            'newest' => $found->sortByDesc(fn (Property $property): int => (int) $property->created_at?->getTimestamp()),
            // §16.11: best rated first; a listing nobody has reviewed yet
            // after every one somebody has, rather than scored as zero.
            'rating' => $found->sortBy([
                fn (Property $a, Property $b): int => ($b->rating()['average'] ?? -1) <=> ($a->rating()['average'] ?? -1),
                fn (Property $a, Property $b): int => ($b->rating()['count'] ?? 0) <=> ($a->rating()['count'] ?? 0),
            ]),
            default => $found->sortBy([['sort_order', 'asc'], ['id', 'asc']]),
        };
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
        // The same rule as search — §16.7. A listing waiting for approval,
        // or from a suspended host, is not readable by guessing its name.
        abort_unless($property->isListable(), 404);

        $service = $property->type === Property::RENTAL ? 'stays_rooms' : 'stays_guesthouses';

        if (ServiceRegistry::isOff($service)) {
            throw new NotFoundHttpException;
        }

        $filters = StayFilters::fromRequest($request);

        $property->load(['roomTypes', 'partner.page', 'photos.roomType']);

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
            // Whether this reader owes Green Tax at all — §16.3 decision 6.
            // A local stay is exempt unless the owner configures otherwise,
            // and telling a Maldivian family about a foreigners' tax would
            // be a line of worry about money nobody will ask them for.
            'greenTaxApplies' => $this->greenTax->appliesTo($filters->audience),
            'greenTaxAtProperty' => $this->greenTax->isCollectedAtProperty($property),
            'greenTaxRate' => $this->greenTax->perGuestPerNight(),
            'greenTaxEstimate' => $guests !== null && $nights > 0
                ? $this->greenTax->forParty($guests, $nights)
                : null,
            'rooms' => $this->priceRooms($property, $filters),
            // §16 Phase 15: what can be added at booking, for this reader.
            'addons' => StayAddons::offered($property->addons()->where('is_active', true)->with('property')->get(), $filters->audience),
            'photos' => $this->gallery($property),
            'bookable' => ServiceRegistry::isOn($service),
            'shareCard' => $this->shareCardUrl($property),
            'hasFactSheet' => in_array(app()->getLocale(), self::SHEET_LOCALES, true),
            'socialSettings' => Setting::getSocialSettings(),
            // §16.11: visible reviews only, newest first.
            'rating' => $property->rating(),
            'reviews' => $property->reviews()->visible()->with('customer')->latest('submitted_at')->paginate(10, ['*'], 'reviews'),
        ]);
    }

    /**
     * The listing's photographs, as the gallery and its lightbox read them.
     *
     * A photo with no caption is described by its room, or by the building,
     * so no image reaches a screen reader with nothing to say.
     *
     * @return list<array{src: string, alt: string, caption: string}>
     */
    private function gallery(Property $property): array
    {
        return $property->photos->map(fn (PropertyPhoto $photo): array => [
            'src' => Storage::disk($photo->disk)->url($photo->path),
            'alt' => (string) ($photo->caption ?: ($photo->roomType->name ?? $property->name)),
            'caption' => (string) ($photo->caption ?: ($photo->roomType->name ?? '')),
        ])->values()->all();
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
        abort_unless($property->isListable(), 404);

        if (ServiceRegistry::isOff($property->type === Property::RENTAL ? 'stays_rooms' : 'stays_guesthouses')) {
            throw new NotFoundHttpException;
        }

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
        abort_unless($property->isListable(), 404);

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

        $properties = Property::listable()
            ->ofType([$type])
            ->with(['roomTypes'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($properties->isEmpty()) {
            return $this->comingSoon($service, $blurb);
        }

        $shown = $filters->apply(
            Property::listable()->ofType([$type])->with(['roomTypes'])
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
        $audience = $filters->audience;

        return $property->roomTypes->map(function (RoomType $room) use ($filters, $audience, $property): array {
            // Not sold to this audience at all — §16.3 decision 6. Said on
            // the card rather than the room hidden, so a Maldivian reader
            // knows the room exists and why they cannot have it.
            if (! $this->availability->offers($room, $audience)) {
                return ['room' => $room, 'offered' => false, 'nightly' => null, 'quote' => null, 'available' => null, 'reason' => null];
            }

            $nightly = $audience === Audience::LOCAL
                ? ($room->local_rate_minor !== null ? Money::ofMinor($room->local_rate_minor, Audience::currencyAt($property, $audience)) : null)
                : $room->baseRate();

            if (! $filters->hasDates()) {
                return ['room' => $room, 'offered' => true, 'nightly' => $nightly, 'quote' => null, 'available' => null, 'reason' => null];
            }

            try {
                $quote = $this->availability->quote($room, $filters->checkIn, $filters->checkOut, $audience);
            } catch (NotSoldToAudience) {
                // A local season that covers some of the nights and not
                // the rest: the room is theirs to buy, just not for these
                // dates.
                return ['room' => $room, 'offered' => true, 'nightly' => $nightly, 'quote' => null, 'available' => false, 'reason' => null];
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
                'offered' => true,
                'nightly' => $nightly,
                'quote' => $quote,
                'available' => $available,
                'reason' => $reason,
            ];
        })->all();
    }
}
