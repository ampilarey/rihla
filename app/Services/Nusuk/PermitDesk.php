<?php

namespace App\Services\Nusuk;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\NusukPermit;
use App\Models\Traveller;
use Illuminate\Support\Collection;

/**
 * Opening Nusuk permits, and re-requesting them after a refusal.
 *
 * Knows nothing about visas [R-4]. The two are different authorisations from
 * different systems, and the whole reason they are apart is so the
 * application can say "visa issued, permit refused" — the state that strands
 * a pilgrim, and the one a single combined field cannot express.
 */
final class PermitDesk
{
    /**
     * The permit somebody is working on, creating it if there is none.
     *
     * Idempotent per traveller per kind while one is open: pressing the
     * button twice does not put two requests into a Saudi system for one
     * person.
     */
    public function open(Booking $booking, Traveller $traveller, string $kind = NusukPermit::UMRAH): NusukPermit
    {
        $existing = NusukPermit::where('booking_id', $booking->getKey())
            ->where('traveller_id', $traveller->getKey())
            ->ofKind($kind)
            ->orderByDesc('attempt')
            ->first();

        if ($existing instanceof NusukPermit && ! $existing->isClosed()) {
            return $existing;
        }

        return NusukPermit::create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $traveller->getKey(),
            'kind' => $kind,
            'attempt' => ($existing->attempt ?? 0) + 1,
            'assigned_to' => $existing?->assigned_to,
        ]);
    }

    /**
     * One Umrah permit per traveller on the booking.
     *
     * Umrah only, and Rawdah slots are asked for individually: not everybody
     * wants one, the slots are scarce, and requesting one for a party that
     * did not ask spends a slot somebody else needed.
     *
     * @return Collection<int, NusukPermit>
     */
    public function openForBooking(Booking $booking, string $kind = NusukPermit::UMRAH): Collection
    {
        return $booking->travellers()
            ->with('traveller')
            ->get()
            ->map(fn (BookingTraveller $line): NusukPermit => $this->open($booking, $line->traveller, $kind));
    }

    /** A refusal ends a permit; asking again is a new attempt. */
    public function rerequest(NusukPermit $refused): NusukPermit
    {
        return NusukPermit::create([
            'booking_id' => $refused->booking_id,
            'traveller_id' => $refused->traveller_id,
            'kind' => $refused->kind,
            'attempt' => $refused->attempt + 1,
            'assigned_to' => $refused->assigned_to,
        ]);
    }
}
