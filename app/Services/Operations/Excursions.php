<?php

namespace App\Services\Operations;

use App\Exceptions\DeskRefusal;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\DepartureExcursion;
use App\Models\ExcursionBooking;
use App\Models\ExcursionParticipant;
use Illuminate\Support\Facades\DB;

/**
 * Signs people up for an additional trip and takes them off it (companies
 * plan, Phase 3e, from Hijaz).
 *
 * People are grouped by the booking they travel on: each family gets one
 * {@see ExcursionBooking} per trip, which is what is invoiced and paid.
 * Each place is priced when it is taken and keeps that price. A trip that
 * is full refuses the rest and says who; a cancelled family's places stop
 * counting against it.
 */
final class Excursions
{
    /** Bookings whose people may go: holding a seat, confirmed, or travelling. */
    public const ELIGIBLE = [Booking::HELD, Booking::CONFIRMED, Booking::COMPLETED];

    /**
     * @param  list<int>  $bookingTravellerIds
     * @return array{added: int, skipped: list<string>, bookings: list<string>}
     */
    public function signUp(DepartureExcursion $excursion, array $bookingTravellerIds): array
    {
        return DB::transaction(function () use ($excursion, $bookingTravellerIds): array {
            // The trip row is the lock: two people filling the last seats
            // at once take them one after the other.
            $excursion = DepartureExcursion::query()->lockForUpdate()->findOrFail($excursion->getKey());

            $added = 0;
            $skipped = [];
            $touched = [];
            $left = $excursion->placesLeft();

            $members = BookingTraveller::query()
                ->whereKey($bookingTravellerIds)
                ->with(['traveller', 'booking'])
                ->get()
                ->sortBy('id');

            foreach ($members as $member) {
                $booking = $member->booking;

                if ((int) $booking->departure_id !== (int) $excursion->departure_id || ! in_array($booking->status, self::ELIGIBLE, true)) {
                    $skipped[] = $member->traveller->full_name.': not travelling on this departure';

                    continue;
                }

                $xb = ExcursionBooking::query()->firstOrCreate(
                    ['departure_excursion_id' => $excursion->getKey(), 'booking_id' => $booking->getKey()],
                    ['customer_id' => $booking->customer_id, 'currency' => $excursion->currency],
                );

                if ($xb->status === ExcursionBooking::CANCELLED) {
                    $skipped[] = $member->traveller->full_name.': their family\'s places were cancelled ('.$xb->reference.')';

                    continue;
                }

                if ($xb->participants()->where('booking_traveller_id', $member->getKey())->exists()) {
                    continue;
                }

                if ($left !== null && $left <= 0) {
                    $skipped[] = $member->traveller->full_name.': the trip is full';

                    continue;
                }

                $xb->participants()->create([
                    'booking_traveller_id' => $member->getKey(),
                    'amount_minor' => $excursion->price()->minor,
                ]);

                $added++;
                $left = $left === null ? null : $left - 1;
                $touched[$xb->getKey()] = $xb;
            }

            foreach ($touched as $xb) {
                $xb->recalculateTotal();
            }

            return [
                'added' => $added,
                'skipped' => $skipped,
                'bookings' => array_values(array_map(fn (ExcursionBooking $xb): string => (string) $xb->fresh()?->reference, $touched)),
            ];
        });
    }

    /** Take one person off. Refused once their family has paid, because the money would then be owed back. */
    public function remove(ExcursionParticipant $participant): void
    {
        DB::transaction(function () use ($participant): void {
            $xb = ExcursionBooking::query()->lockForUpdate()->findOrFail($participant->excursion_booking_id);

            if ($xb->paid_minor > 0 && $participant->amount_minor > 0) {
                throw new DeskRefusal('Their family has paid for this trip. Refund the payment first, then take them off.');
            }

            $participant->delete();
            $xb->recalculateTotal();
        });
    }

    /** Cancel a family's places. Refused while money is held against them. */
    public function cancel(ExcursionBooking $booking, ?string $reason = null): void
    {
        DB::transaction(function () use ($booking, $reason): void {
            $xb = ExcursionBooking::query()->lockForUpdate()->findOrFail($booking->getKey());

            if ($xb->paid_minor > 0) {
                throw new DeskRefusal('Money has been received for these places. Refund it first, then cancel.');
            }

            $xb->forceFill([
                'status' => ExcursionBooking::CANCELLED,
                'notes' => trim(($xb->notes ? $xb->notes."\n" : '').'Cancelled'.($reason ? ': '.$reason : '')),
            ])->save();
        });
    }
}
