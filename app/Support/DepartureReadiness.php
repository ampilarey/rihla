<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Departure;
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
 * Flights, transport and supplier coordination (§8.2, §8.3) have no records
 * in this system yet. Reporting "flights: fine" from the absence of data
 * would be a lie of exactly the kind this codebase has been bitten by
 * before, so they are absent rather than green.
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
        );
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
