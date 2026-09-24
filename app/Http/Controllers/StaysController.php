<?php

namespace App\Http\Controllers;

use App\Exceptions\RoomNotAvailable;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\Stays\Availability;
use App\Support\Services as ServiceRegistry;
use App\Support\StayFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
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
    public function __construct(private readonly Availability $availability) {}

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

    public function islandHolidays(Request $request): View
    {
        // Island holidays run on the *package* engine, not this one — §15.5
        // (Phase 10). Nothing lists here yet, and saying so is better than
        // an empty grid pretending the catalogue is bare.
        return $this->comingSoon('stays_island_holidays', __(
            'messages.Short island holidays for Maldivian families — a weekend away, arranged the way our Umrah groups already are. Tell us which island and when, and we will put together a plan.',
        ));
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

        return view('stays.show', [
            'property' => $property,
            'filters' => $filters,
            'rooms' => $this->priceRooms($property, $filters),
            'bookable' => ServiceRegistry::isOn($service),
            'socialSettings' => Setting::getSocialSettings(),
        ]);
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
