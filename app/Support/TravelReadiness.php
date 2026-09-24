<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\Document;
use App\Models\NusukPermit;
use App\Models\Traveller;
use App\Models\VisaApplication;

/**
 * Whether a traveller can actually go — **computed, never stored**.
 *
 * §5.4a is explicit: "travel readiness is computed from these records, never
 * stored as a booking field". A stored flag is wrong from the moment any of
 * the underlying records changes, and right again only if something
 * remembers to recompute it. The one place that matters most is the one
 * where a stale "ready" would send somebody to the airport.
 *
 * ## Three independent requirements, and that is the point [R-4]
 *
 * A passport, a visa and an Umrah permit are granted by different bodies and
 * fail differently. Under the 2026 rules a traveller can hold a valid visa
 * and still be barred from the Mataf without a permit — so this returns each
 * requirement separately rather than one score. "Not ready" is useless to
 * the person who has to fix it; "visa issued, permit not requested" is a
 * morning's work.
 *
 * ## A Rawdah slot is deliberately not a requirement
 *
 * Missing it is a disappointment; missing an Umrah permit is a wasted
 * journey. Folding the two together would make a pilgrim who cannot pray in
 * the Rawdah look like a pilgrim who cannot perform Umrah, and the only
 * useful thing this class does is tell those two apart.
 */
final class TravelReadiness
{
    public const PASSPORT = 'passport';

    public const VISA = 'visa';

    public const PERMIT = 'permit';

    /** @var list<string> */
    public const REQUIREMENTS = [self::PASSPORT, self::VISA, self::PERMIT];

    /**
     * The requirements this booking's product actually has — §15.5
     * (Phase 10).
     *
     * An island holiday has none. A Maldivian family catching the Thursday
     * ferry to Ukulhas needs no passport, no visa and no Umrah permit, and
     * asking for them is not a harmless extra field: it is a form somebody
     * abandons, and a board reading "not ready" for a family who are
     * entirely ready to go.
     *
     * Read from the package rather than listed at each call site, so a
     * fifth place that cares cannot be written without meeting it.
     *
     * @return list<string>
     */
    public static function requiredFor(Booking $booking): array
    {
        $package = $booking->departure?->package;

        // No package reachable is not a licence to ask for nothing. A
        // booking whose departure or package has gone is a broken row, and
        // the safe reading of a broken row is the strict one — better to
        // ask a family for a passport they do not need than to send a
        // pilgrim to Jeddah without a visa.
        if ($package === null) {
            return self::REQUIREMENTS;
        }

        return $package->needsTravelDocuments() ? self::REQUIREMENTS : [];
    }

    /**
     * Each requirement this booking actually has, met or not.
     *
     * An island holiday returns an empty array, which {@see isReady()}
     * correctly reads as ready: there is nothing standing between that
     * family and the ferry.
     *
     * @return array<string, bool>
     */
    public static function forTraveller(Booking $booking, Traveller $traveller): array
    {
        $answers = [
            self::PASSPORT => fn (): bool => self::hasUsablePassport($traveller, $booking->departure),
            self::VISA => fn (): bool => self::hasIssuedVisa($booking, $traveller),
            self::PERMIT => fn (): bool => self::hasIssuedUmrahPermit($booking, $traveller),
        ];

        $required = [];

        foreach (self::requiredFor($booking) as $requirement) {
            $required[$requirement] = $answers[$requirement]();
        }

        return $required;
    }

    public static function isReady(Booking $booking, Traveller $traveller): bool
    {
        return ! in_array(false, self::forTraveller($booking, $traveller), true);
    }

    /**
     * What is missing, for whom, on one booking.
     *
     * @return array<string, list<string>> traveller name => unmet requirements
     */
    public static function blockers(Booking $booking): array
    {
        $blockers = [];

        foreach ($booking->travellers as $line) {
            $unmet = array_keys(array_filter(
                self::forTraveller($booking, $line->traveller),
                fn (bool $met): bool => ! $met,
            ));

            // No array_values(): array_keys() already returns a list, and
            // static analysis calls the extra call dead.
            if ($unmet !== []) {
                $blockers[$line->traveller->full_name] = $unmet;
            }
        }

        return $blockers;
    }

    /**
     * §5.4a's gate: "a departure cannot be marked ready while any traveller
     * lacks a permit".
     *
     * Only confirmed bookings count. A draft or a lapsed hold is not a
     * person who is going, and counting them would make every departure
     * permanently un-ready for travellers who were never travelling.
     */
    public static function departureIsReady(Departure $departure): bool
    {
        return self::departureBlockers($departure) === [];
    }

    /**
     * Everybody on the departure who cannot go yet, and why.
     *
     * @return array<string, array<string, list<string>>> booking reference => blockers
     */
    public static function departureBlockers(Departure $departure): array
    {
        $blockers = [];

        $bookings = $departure->bookings()
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->with(['travellers.traveller', 'departure'])
            ->get();

        foreach ($bookings as $booking) {
            $unmet = self::blockers($booking);

            if ($unmet !== []) {
                $blockers[(string) $booking->reference] = $unmet;
            }
        }

        return $blockers;
    }

    /**
     * A passport on file, checked, and still valid far enough past the
     * departure date.
     *
     * Verified rather than merely uploaded: an unread scan is a file, not a
     * document. The validity window is configuration (§5.4b) and is measured
     * from the departure, not from today.
     */
    private static function hasUsablePassport(Traveller $traveller, ?Departure $departure): bool
    {
        $passport = Document::where('traveller_id', $traveller->getKey())
            ->where('type', Document::PASSPORT)
            ->where('status', Document::VERIFIED)
            ->latest('id')
            ->first();

        if (! $passport instanceof Document) {
            return false;
        }

        return ! $passport->expiresWithinWindow($departure?->date_start);
    }

    private static function hasIssuedVisa(Booking $booking, Traveller $traveller): bool
    {
        return VisaApplication::forBooking($booking)
            ->where('traveller_id', $traveller->getKey())
            ->where('status', VisaApplication::ISSUED)
            ->exists();
    }

    /**
     * The Umrah permit, and only that one. A Rawdah slot is not a
     * requirement — see the class docblock.
     */
    private static function hasIssuedUmrahPermit(Booking $booking, Traveller $traveller): bool
    {
        return NusukPermit::where('booking_id', $booking->getKey())
            ->where('traveller_id', $traveller->getKey())
            ->ofKind(NusukPermit::UMRAH)
            ->where('status', NusukPermit::ISSUED)
            ->exists();
    }
}
