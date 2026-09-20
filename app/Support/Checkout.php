<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\SeatHold;
use Illuminate\Support\Facades\Session;

/**
 * Where a visitor is in the booking flow.
 *
 * Two identifiers in the session and nothing else. The state that matters —
 * which seats are held, when they lapse, what has been entered — lives in
 * the database, where it survives a closed tab and can be seen by the people
 * who will have to answer the phone about it.
 *
 * Deliberately **not** in the URL. A booking reference in the path would let
 * anyone who guessed one read a stranger's passport details and phone
 * number; the reference is something to quote to staff, not a key.
 */
final class Checkout
{
    private const HOLD = 'checkout.hold';

    private const OCCUPANCY = 'checkout.occupancy';

    private const BOOKING = 'checkout.booking';

    /**
     * Set when the checkout was entered from a waiting-list offer, so the
     * entry can be marked converted once the booking exists. Without it a
     * promoted party would book and still show as waiting for ever.
     */
    private const WAITLIST = 'checkout.waitlist';

    public static function remember(SeatHold $hold, string $occupancy): void
    {
        Session::put(self::HOLD, $hold->getKey());
        Session::put(self::OCCUPANCY, $occupancy);
        Session::forget([self::BOOKING, self::WAITLIST]);
    }

    public static function rememberWaitlistEntry(int $id): void
    {
        Session::put(self::WAITLIST, $id);
    }

    public static function waitlistEntryId(): ?int
    {
        $id = Session::get(self::WAITLIST);

        return is_int($id) ? $id : null;
    }

    public static function attach(Booking $booking): void
    {
        Session::put(self::BOOKING, $booking->getKey());
    }

    /** The hold, or null if there is none, it lapsed, or it was released. */
    public static function hold(): ?SeatHold
    {
        $hold = SeatHold::with('departure.package')->find(Session::get(self::HOLD));

        return $hold?->isLive() === true ? $hold : null;
    }

    public static function occupancy(): ?string
    {
        $value = Session::get(self::OCCUPANCY);

        return is_string($value) ? $value : null;
    }

    public static function booking(): ?Booking
    {
        return Booking::with(['departure.package', 'travellers.traveller', 'lines', 'customer'])
            ->find(Session::get(self::BOOKING));
    }

    public static function clear(): void
    {
        Session::forget([self::HOLD, self::OCCUPANCY, self::BOOKING, self::WAITLIST]);
    }
}
