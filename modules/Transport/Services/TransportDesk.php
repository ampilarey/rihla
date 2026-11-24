<?php

namespace Modules\Transport\Services;

use App\Exceptions\DeskRefusal;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\BoatTicket;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Models\Vessel;

/**
 * Every change to a boat seat goes through here — docs/TRANSPORT_PLAN.md.
 *
 * ## One lock, one counter
 *
 * `sailings.seats_taken` is held plus confirmed seats, and it is only ever
 * read and written inside `lockForUpdate()` on the sailing's row, as
 * `SeatAllocator` does for Umrah departures. Counting bookings at read time
 * instead would let two bookers each see the last seat free.
 *
 * Lapsed holds are reclaimed under the same lock before a new hold is
 * counted, so an abandoned booking never keeps a full boat full.
 */
final class TransportDesk
{
    /**
     * Hold seats for these passengers on this sailing.
     *
     * @param  list<array{full_name: string, is_child?: bool}>  $passengers
     */
    public function book(Sailing $sailing, array $passengers, string $contactName, string $contactPhone, ?Customer $customer = null, ?User $by = null, ?CarbonInterface $heldUntil = null): BoatBooking
    {
        $passengers = array_values(array_filter($passengers, fn (array $p): bool => trim($p['full_name']) !== ''));

        if ($passengers === []) {
            throw new DeskRefusal('Name at least one passenger.');
        }

        return DB::transaction(function () use ($sailing, $passengers, $contactName, $contactPhone, $customer, $by, $heldUntil): BoatBooking {
            $locked = $this->lock($sailing);

            if (! $locked->isOnSale()) {
                throw new DeskRefusal('That sailing is not taking bookings.');
            }

            $this->reclaimLapsedOn($locked);
            $seats = count($passengers);

            if ($seats > $locked->seatsLeft()) {
                throw new DeskRefusal($locked->seatsLeft() === 0
                    ? 'That sailing is full.'
                    : 'Only '.$locked->seatsLeft().' '.($locked->seatsLeft() === 1 ? 'seat is' : 'seats are').' left on that sailing.');
            }

            /** @var BoatRoute $route */
            $route = $locked->route;
            $total = array_sum(array_map(fn (array $p): int => $route->fare((bool) ($p['is_child'] ?? false)), $passengers));

            $booking = new BoatBooking;
            $booking->forceFill([
                'sailing_id' => $locked->getKey(),
                'customer_id' => $customer?->getKey(),
                'reference' => 'pending-'.Str::random(16),
                'tickets_key' => Str::random(40),
                'contact_name' => trim($contactName),
                'contact_phone' => trim($contactPhone),
                'seats' => $seats,
                'currency' => $route->currency,
                'total_minor' => $total,
                'status' => BoatBooking::HELD,
                'held_until' => $heldUntil ?? now()->addMinutes((int) config('transport.hold_minutes', 15)),
                'booked_by' => $by?->getKey(),
            ])->save();
            $booking->forceFill(['reference' => sprintf('RIH-T-%s-%05d', now()->format('Y'), $booking->getKey())])->save();

            foreach ($passengers as $passenger) {
                $ticket = new BoatTicket;
                $ticket->forceFill([
                    'boat_booking_id' => $booking->getKey(),
                    'full_name' => trim($passenger['full_name']),
                    'is_child' => (bool) ($passenger['is_child'] ?? false),
                    'token' => Str::random(40),
                ])->save();
            }

            $locked->forceFill(['seats_taken' => $locked->seats_taken + $seats])->save();
            $sailing->setRawAttributes($locked->getAttributes(), sync: true);

            return $booking;
        });
    }

    /** Staff confirm a held booking (paid at the desk, or trusted to pay). */
    public function confirm(BoatBooking $booking): void
    {
        if ($booking->status !== BoatBooking::HELD) {
            throw new DeskRefusal('Only a held booking can be confirmed.');
        }

        $booking->forceFill(['status' => BoatBooking::CONFIRMED, 'held_until' => null])->save();
    }

    /** Cancel a booking and give its seats back. */
    public function cancel(BoatBooking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            $locked = $this->lock($booking->sailing);
            $booking->refresh();

            if (! $booking->holdsSeats()) {
                throw new DeskRefusal('That booking is already '.strtolower(BoatBooking::STATUSES[$booking->status] ?? $booking->status).'.');
            }

            $booking->forceFill(['status' => BoatBooking::CANCELLED, 'held_until' => null])->save();
            $locked->forceFill(['seats_taken' => max(0, $locked->seats_taken - $booking->seats)])->save();
        });
    }

    /**
     * Every lapsed hold on every sailing, for the scheduled sweep.
     *
     * @return int seats given back
     */
    public function reclaimLapsed(): int
    {
        $released = 0;

        Sailing::query()
            ->whereIn('id', BoatBooking::query()->where('status', BoatBooking::HELD)->where('held_until', '<', now())->select('sailing_id'))
            ->each(function (Sailing $sailing) use (&$released): void {
                $released += DB::transaction(fn (): int => $this->reclaimLapsedOn($this->lock($sailing)));
            });

        return $released;
    }

    /**
     * A week's (or any range's) sailings from a timetable: this route, this
     * boat, these weekdays at these times. A sailing that already exists at
     * that moment is left alone, so running it twice adds nothing.
     *
     * @param  list<int>  $weekdays  ISO-8601, 1 = Monday
     * @param  list<string>  $times  "07:30"
     * @return int sailings created
     */
    public function schedule(BoatRoute $route, Vessel $vessel, CarbonInterface $from, CarbonInterface $to, array $weekdays, array $times, ?int $seats = null): int
    {
        if ($vessel->partner_id !== $route->partner_id) {
            throw new DeskRefusal('That boat belongs to another operator.');
        }

        if ($from->gt($to) || $from->diffInDays($to) > 120) {
            throw new DeskRefusal('Choose a range of up to 120 days, ending after it starts.');
        }

        $seats = min($seats ?? $vessel->seats, $vessel->seats);
        $created = 0;

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day = $day->copy()->addDay()) {
            if (! in_array($day->dayOfWeekIso, $weekdays, true)) {
                continue;
            }

            foreach ($times as $time) {
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                    throw new DeskRefusal('"'.$time.'" is not a time like 07:30.');
                }

                $departsAt = $day->copy()->setTimeFromTimeString($time);

                if ($departsAt->isPast()) {
                    continue;
                }

                $sailing = Sailing::query()->firstOrCreate(
                    ['boat_route_id' => $route->getKey(), 'departs_at' => $departsAt],
                    ['vessel_id' => $vessel->getKey(), 'seats_total' => $seats],
                );

                $created += $sailing->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $created;
    }

    /** Cancel a sailing, with a reason, and every booking on it. */
    public function cancelSailing(Sailing $sailing, string $reason): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DeskRefusal('Say why: every booker will be told.');
        }

        return DB::transaction(function () use ($sailing, $reason): int {
            $locked = $this->lock($sailing);

            if ($locked->status === Sailing::CANCELLED || $locked->status === Sailing::DEPARTED) {
                throw new DeskRefusal('That sailing has already '.($locked->status === Sailing::DEPARTED ? 'left' : 'been cancelled').'.');
            }

            $cancelled = $locked->bookings()->whereIn('status', BoatBooking::HOLDS_SEATS)->update(['status' => BoatBooking::CANCELLED, 'held_until' => null]);
            $locked->forceFill(['status' => Sailing::CANCELLED, 'cancelled_reason' => $reason, 'seats_taken' => 0])->save();

            return $cancelled;
        });
    }

    private function lock(Sailing $sailing): Sailing
    {
        /** @var Sailing */
        return Sailing::query()->whereKey($sailing->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Lapse this sailing's expired holds and give their seats back. Inside the lock. */
    private function reclaimLapsedOn(Sailing $locked): int
    {
        $lapsed = $locked->bookings()->where('status', BoatBooking::HELD)->where('held_until', '<', now());
        $seats = (int) (clone $lapsed)->sum('seats');

        if ($seats === 0) {
            return 0;
        }

        $lapsed->update(['status' => BoatBooking::LAPSED, 'held_until' => null]);
        $locked->forceFill(['seats_taken' => max(0, $locked->seats_taken - $seats)])->save();

        return $seats;
    }
}
