<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Departure;
use App\Models\DepartureFlight;
use App\Models\ModuleCompletion;
use App\Models\Traveller;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Collection;

/**
 * Is this departure ready to fly — §8.2's "journey workspace", the half of
 * it that is a question rather than a data-entry screen.
 *
 * ## Computed, never stored
 *
 * The same reasoning as {@see TravelReadiness}: a stored "ready" flag is
 * wrong from the moment any underlying record changes, and right again only
 * if something remembers to recompute it. Here the cost of a stale flag is
 * a departure that nobody checks because the board said it was fine.
 *
 * ## Named concerns, not a score
 *
 * §8.2 asks for a "readiness score". A single number is the wrong shape for
 * this job: "72%" tells the person who has to act nothing at all, while
 * "three passports missing, one hotel's rooming unsettled, no scholar" is a
 * morning's work. So this returns a list of concerns, each with a severity
 * and a sentence, and a boolean for whether anything blocks departure.
 *
 * ## Blocking and worth knowing are different
 *
 * A traveller without an Umrah permit **cannot go**. A departure with an
 * unsettled rooming list will go, and somebody will have an argument at a
 * hotel desk. Folding the two together would either raise a false alarm on
 * every departure or bury the real one, so they are separate severities and
 * `hasBlockers()` counts only the first.
 *
 * ## What this deliberately does not check
 *
 * Supplier coordination — hotel contracts, allotments — has no records in
 * this system. Reporting "suppliers: fine" from the absence of data would
 * be a lie of exactly the kind this codebase has been bitten by before, so
 * it is absent rather than green. Flights and ground transport were in the
 * same position until §8.3 gave them records; see {@see flightConcerns()}.
 */
final class DepartureReadiness
{
    /** Somebody cannot travel, or the departure cannot run as sold. */
    public const BLOCKING = 'blocking';

    /** It will go ahead, and somebody will have a bad time. */
    public const ATTENTION = 'attention';

    public const TRAVEL_DOCUMENTS = 'travel_documents';

    public const MONEY = 'money';

    public const ROOMING = 'rooming';

    public const STAFFING = 'staffing';

    public const CAPACITY = 'capacity';

    public const WAITLIST = 'waitlist';

    public const EMERGENCY_CONTACT = 'emergency_contact';

    public const LEARNING = 'learning';

    public const FLIGHTS = 'flights';

    public const TRANSPORT = 'transport';

    public const CHECKLIST = 'checklist';

    /**
     * Everything worth a person's attention on this departure.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    public static function concerns(Departure $departure): array
    {
        return array_merge(
            self::travelDocumentConcerns($departure),
            self::moneyConcerns($departure),
            self::roomingConcerns($departure),
            self::staffingConcerns($departure),
            self::capacityConcerns($departure),
            self::waitlistConcerns($departure),
            self::emergencyContactConcerns($departure),
            self::learningConcerns($departure),
            self::flightConcerns($departure),
            self::transportConcerns($departure),
            self::checklistConcerns($departure),
        );
    }

    /**
     * Who has not started the pre-departure reading — §7.3's business
     * tie-in, at the honest strength.
     *
     * **Attention, never blocking.** A pilgrim who has read nothing still
     * travels; making this a blocker would mean the platform withholding
     * somebody's Umrah over homework, which is not a thing anybody sells
     * and not a thing anybody should build. What it is worth is a phone
     * call while there is still time, and that is what a named concern is
     * for.
     *
     * Silent while nothing is published. Reporting "nobody has started"
     * when there is nothing to start is the kind of alarm that teaches a
     * board to be ignored.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function learningConcerns(Departure $departure): array
    {
        $plan = StudyPlan::build($departure);

        if ($plan->total() === 0 || $plan->isHistory()) {
            return [];
        }

        // A subquery rather than `whereHas('bookingTravellers.booking')`:
        // a dotted relation path gives the closure a bare `Builder<Model>`,
        // so the scope on it cannot be typed. This also reads as what it is
        // — the people on this departure's live bookings.
        $travellerIds = BookingTraveller::query()
            ->whereIn(
                'booking_id',
                Booking::query()->where('departure_id', $departure->getKey())->active()->select('id'),
            )
            ->pluck('traveller_id')
            ->unique();

        if ($travellerIds->isEmpty()) {
            return [];
        }

        $started = ModuleCompletion::whereIn('traveller_id', $travellerIds)
            ->whereNotNull('read_at')
            ->distinct()
            ->count('traveller_id');

        $notStarted = $travellerIds->count() - $started;

        if ($notStarted < 1) {
            return [];
        }

        return [[
            'area' => self::LEARNING,
            'severity' => self::ATTENTION,
            'headline' => $notStarted === 1
                ? 'One traveller has not opened the reading'
                : $notStarted.' travellers have not opened the reading',
            'detail' => 'Out of '.$travellerIds->count().' on this departure. Nobody is stopped from travelling by this — it is a call worth making while there is still time.',
        ]];
    }

    /** Whether anything on this departure stops somebody travelling. */
    public static function hasBlockers(Departure $departure): bool
    {
        foreach (self::concerns($departure) as $concern) {
            if ($concern['severity'] === self::BLOCKING) {
                return true;
            }
        }

        return false;
    }

    /**
     * Passports, visas and permits, counted by requirement rather than by
     * person.
     *
     * "Four travellers are not ready" is a number; "three passports, one
     * permit" is the two phone calls that fix it. [R-4] keeps the three
     * apart because they are granted by different bodies and fail
     * differently.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function travelDocumentConcerns(Departure $departure): array
    {
        $counts = array_fill_keys(TravelReadiness::REQUIREMENTS, 0);
        $people = 0;

        foreach (TravelReadiness::departureBlockers($departure) as $byTraveller) {
            foreach ($byTraveller as $unmet) {
                $people++;

                foreach ($unmet as $requirement) {
                    $counts[$requirement]++;
                }
            }
        }

        if ($people === 0) {
            return [];
        }

        $parts = [];

        foreach ($counts as $requirement => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.self::requirementWords($requirement, $count);
            }
        }

        return [[
            'area' => self::TRAVEL_DOCUMENTS,
            'severity' => self::BLOCKING,
            'headline' => $people === 1
                ? 'One traveller cannot go yet'
                : $people.' travellers cannot go yet',
            'detail' => 'Outstanding: '.self::sentenceList($parts).'.',
        ]];
    }

    /**
     * Money still owed on confirmed bookings.
     *
     * Blocking, because a departure that flies with an unpaid balance is a
     * debt nobody will collect once the pilgrim is home. Summed in integer
     * minor units and formatted once through {@see Money}, never a float
     * [R-7].
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function moneyConcerns(Departure $departure): array
    {
        /** @var array<string, array{minor: int, bookings: int}> $owed */
        $owed = [];

        foreach (self::confirmedBookings($departure) as $booking) {
            $outstanding = $booking->total_minor - $booking->paid_minor;

            if ($outstanding <= 0) {
                continue;
            }

            // Keyed by currency rather than summed into one number. Every
            // booking today is in rufiyaa, but adding laari to cents the
            // day one is not is the kind of arithmetic nobody checks again.
            $currency = (string) $booking->currency;
            $owed[$currency]['minor'] = ($owed[$currency]['minor'] ?? 0) + $outstanding;
            $owed[$currency]['bookings'] = ($owed[$currency]['bookings'] ?? 0) + 1;
        }

        $concerns = [];

        foreach ($owed as $currency => $totals) {
            $concerns[] = [
                'area' => self::MONEY,
                'severity' => self::BLOCKING,
                'headline' => Money::ofMinor($totals['minor'], $currency)->format().' still owed',
                'detail' => $totals['bookings'] === 1
                    ? 'One confirmed booking has a balance outstanding.'
                    : $totals['bookings'].' confirmed bookings have a balance outstanding.',
            ];
        }

        return $concerns;
    }

    /**
     * Rooming, per hotel stay.
     *
     * Attention rather than blocking: the departure will go, and the
     * argument happens at the hotel desk. See {@see Rooming} for the six
     * checks and for why nothing is allocated automatically.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function roomingConcerns(Departure $departure): array
    {
        $concerns = [];

        foreach ($departure->hotels as $hotel) {
            $problems = Rooming::problemsWith($hotel);

            if ($problems === []) {
                continue;
            }

            $concerns[] = [
                'area' => self::ROOMING,
                'severity' => self::ATTENTION,
                'headline' => $hotel->name.' — '.(count($problems) === 1
                    ? 'one thing to fix'
                    : count($problems).' things to fix'),
                'detail' => 'The rooming list for '.$hotel->cityLabel().' is not ready to send.',
            ];
        }

        return $concerns;
    }

    /**
     * A tour leader and a scholar.
     *
     * The leader is blocking: §6.3 makes them the group's operations, and a
     * departure without one has nobody holding the roster. A scholar is
     * attention — it is what Rihla sells, but the flight leaves either way.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function staffingConcerns(Departure $departure): array
    {
        $concerns = [];

        if ($departure->tour_leader_id === null) {
            $concerns[] = [
                'area' => self::STAFFING,
                'severity' => self::BLOCKING,
                'headline' => 'No tour leader',
                'detail' => 'Nobody is assigned to carry the roster and run the group on the ground.',
            ];
        }

        if ($departure->scholar_id === null) {
            $concerns[] = [
                'area' => self::STAFFING,
                'severity' => self::ATTENTION,
                'headline' => 'No scholar',
                'detail' => 'The departure is sold with religious guidance and nobody is assigned to give it.',
            ];
        }

        return $concerns;
    }

    /**
     * Seats held but never confirmed, close to departure.
     *
     * A hold that is still open days before the flight is a seat that
     * could have been sold. Reported rather than released: releasing
     * somebody's seat automatically, near departure, is a decision for a
     * person.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function capacityConcerns(Departure $departure): array
    {
        $held = (int) $departure->capacity_held;

        if ($held <= 0) {
            return [];
        }

        return [[
            'area' => self::CAPACITY,
            'severity' => self::ATTENTION,
            'headline' => $held === 1 ? 'One seat held, not confirmed' : $held.' seats held, not confirmed',
            'detail' => 'A hold near the departure date is a seat that could be sold to somebody waiting.',
        ]];
    }

    /**
     * People waiting while seats sit unsold.
     *
     * Only worth saying when both are true at once — a queue on a full
     * departure is normal, and empty seats with nobody waiting is a
     * marketing problem rather than an operations one.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function waitlistConcerns(Departure $departure): array
    {
        $remaining = (int) $departure->seats_remaining;

        if ($remaining <= 0) {
            return [];
        }

        $waiting = WaitlistEntry::where('departure_id', $departure->getKey())
            ->where('status', WaitlistEntry::WAITING)
            ->count();

        if ($waiting === 0) {
            return [];
        }

        return [[
            'area' => self::WAITLIST,
            'severity' => self::ATTENTION,
            'headline' => $waiting === 1
                ? 'Somebody is waiting for a seat that is free'
                : $waiting.' parties waiting while seats are free',
            'detail' => $remaining.' seat(s) remain and the queue has not been offered them.',
        ]];
    }

    /**
     * Confirmed travellers with nobody to ring — §6.5.
     *
     * Blocking, and that is the point. The moment this matters is the
     * moment nobody has time to go looking for a phone number, so it is
     * caught while somebody can still ask. Configurable because it is an
     * operational policy rather than a fact, and on by default because the
     * safe default for a safeguarding check is on.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function emergencyContactConcerns(Departure $departure): array
    {
        if (! config('broadcasts.require_emergency_contacts', true)) {
            return [];
        }

        $without = Rooming::travellersOwedABed($departure)
            ->filter(fn (Traveller $traveller): bool => blank($traveller->emergency_contact_name)
                || blank($traveller->emergency_contact_phone))
            ->count();

        if ($without === 0) {
            return [];
        }

        return [[
            'area' => self::EMERGENCY_CONTACT,
            'severity' => self::BLOCKING,
            'headline' => $without === 1
                ? 'One traveller has nobody to ring'
                : $without.' travellers have nobody to ring',
            'detail' => 'No emergency contact on file. The moment this matters is the moment nobody has time to go looking.',
        ]];
    }

    /**
     * Flights, as recorded under Travel → Flights & transport — §8.3.
     *
     * **Nothing recorded is attention, not blocking.** The group will fly
     * whether or not somebody typed the flight in; what is missing is the
     * record the portal, the tour leader and the families read. Saying so
     * is the point — this used to be silent, which read as fine.
     *
     * **More travellers than seats is blocking.** When a leg carries a seat
     * count and the confirmed party is bigger, somebody on this departure
     * does not have a seat on that aircraft, and that is a person who
     * cannot go. A leg with no seat count is not compared: an unknown
     * number is not a shortfall.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function flightConcerns(Departure $departure): array
    {
        // Silent until somebody is travelling. A departure nobody has
        // bought a seat on is months from needing its flights typed in,
        // and a board that nags about every unsold date stops being read.
        $travelling = Rooming::travellersOwedABed($departure)->count();

        if ($travelling === 0) {
            return [];
        }

        $flights = $departure->flights()->get();

        if ($flights->isEmpty()) {
            return [[
                'area' => self::FLIGHTS,
                'severity' => self::ATTENTION,
                'headline' => 'No flights recorded',
                'detail' => 'Nothing says how this group gets there or back. Add the legs under Travel → Flights & transport — the portal and the tour leader read them from there.',
            ]];
        }

        $concerns = [];

        if ($flights->where('direction', DepartureFlight::RETURN)->isEmpty()) {
            $concerns[] = [
                'area' => self::FLIGHTS,
                'severity' => self::ATTENTION,
                'headline' => 'No return flight recorded',
                'detail' => 'The way out is recorded and the way home is not.',
            ];
        }

        foreach ($flights as $flight) {
            if ($flight->seats === null || $travelling <= $flight->seats) {
                continue;
            }

            $short = $travelling - $flight->seats;

            $concerns[] = [
                'area' => self::FLIGHTS,
                'severity' => self::BLOCKING,
                'headline' => $flight->label().' — '.($short === 1 ? 'one seat short' : $short.' seats short'),
                'detail' => $travelling.' confirmed travellers and '.$flight->seats.' seats on this leg.',
            ];
        }

        return $concerns;
    }

    /**
     * Ground transport — §8.3. Attention only: a group with no coach
     * recorded still gets from the airport somehow, but nobody in the
     * office can say how, and the pilgrim has no meeting point to read.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function transportConcerns(Departure $departure): array
    {
        // Silent until somebody is travelling, for the reason given above.
        if ($departure->transfers()->exists() || Rooming::travellersOwedABed($departure)->isEmpty()) {
            return [];
        }

        return [[
            'area' => self::TRANSPORT,
            'severity' => self::ATTENTION,
            'headline' => 'No ground transport recorded',
            'detail' => 'No coach, car or train is recorded — not even the airport transfer. Add them under Travel → Flights & transport.',
        ]];
    }

    /**
     * Checklist lines past their date and not done — §8.3.
     *
     * Blocking only for the lines the office marked as blocking; the rest
     * are attention. Not gated on anybody travelling, unlike flights: a
     * line exists because somebody put it there, so it is never noise.
     * Named, not counted, while there are few enough to read — "group
     * visa submitted" is a phone call, "3 items" is a screen to open.
     *
     * @return list<array{area: string, severity: string, headline: string, detail: string}>
     */
    private static function checklistConcerns(Departure $departure): array
    {
        $overdue = $departure->checklist()
            ->open()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', today())
            ->get();

        $concerns = [];

        foreach ([true => self::BLOCKING, false => self::ATTENTION] as $blocking => $severity) {
            $items = $overdue->where('is_blocking', (bool) $blocking);

            if ($items->isEmpty()) {
                continue;
            }

            $concerns[] = [
                'area' => self::CHECKLIST,
                'severity' => $severity,
                'headline' => $items->count() === 1
                    ? 'Overdue: '.$items->first()->title
                    : $items->count().' checklist items overdue',
                'detail' => $items->count() === 1
                    ? 'Due '.$items->first()->due_on->format('j M').' and not done.'
                    : 'Not done: '.self::sentenceList($items->pluck('title')->take(5)->values()->all()).($items->count() > 5 ? ', and more.' : '.'),
            ];
        }

        return $concerns;
    }

    /** @return Collection<int, Booking> */
    private static function confirmedBookings(Departure $departure)
    {
        return $departure->bookings()
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->get();
    }

    /**
     * Whole words rather than a key built by concatenation: a raw key would
     * reach the screen the day a requirement is added, which is exactly
     * what TranslationQualityTest exists to stop.
     */
    private static function requirementWords(string $requirement, int $count): string
    {
        return match ($requirement) {
            TravelReadiness::PASSPORT => $count === 1 ? 'passport' : 'passports',
            TravelReadiness::VISA => $count === 1 ? 'visa' : 'visas',
            TravelReadiness::PERMIT => $count === 1 ? 'Umrah permit' : 'Umrah permits',
            default => $count === 1 ? 'requirement' : 'requirements',
        };
    }

    /** @param list<string> $parts */
    private static function sentenceList(array $parts): string
    {
        if (count($parts) <= 1) {
            return implode('', $parts);
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }
}
