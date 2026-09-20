<?php

namespace App\Services\Booking;

use App\Exceptions\NoSeatsAvailable;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\SeatHold;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only thing in this application that moves a departure's seat counters.
 *
 * ## Why it is written this way
 *
 * Two families in Malé open the same Ramadan departure with one seat left.
 * Both read "1 seat remaining". Both press book. Without serialisation both
 * write `capacity_held = capacity_held + 1` and the departure is oversold —
 * and nobody finds out until the airline refuses a boarding pass.
 *
 * The plan's §5.2 requires this to be prevented at the database, not in PHP.
 * Two mechanisms, deliberately overlapping:
 *
 * 1. **`SELECT … FOR UPDATE` on the departure row** ([R-2]). Every read of
 *    the counters that leads to a write takes the row lock first, so the
 *    second request blocks until the first has committed and then reads the
 *    number the first one wrote. This is what makes the check correct.
 *    SQLite cannot exercise it, which is why CI also runs the suite against
 *    MySQL.
 *
 * 2. **A capacity constraint on the table** (see the migration
 *    `add_the_departure_capacity_constraint`). If any code path ever
 *    forgets the lock — an import script, a console command, a tinker
 *    session at midnight — the write fails instead of overselling.
 *
 * ## Expiry is never trusted to cron
 *
 * A held seat that nobody paid for must go back. Doing that from a
 * scheduled command means that on a host where cron is misconfigured — and
 * this is cPanel shared hosting — seats silently stay held for ever and the
 * departure looks sold out while empty. So a lapsed hold is reclaimed
 * *inside the same row lock* that the next booking takes. The command only
 * tidies up departures nobody is looking at.
 *
 * ## Capacity of zero
 *
 * Means nobody has entered one. Every departure backfilled from `trips`
 * starts there. It is not "unlimited": selling a seat on a departure whose
 * capacity nobody recorded is exactly the situation this class exists to
 * prevent, so it refuses and says what to do about it.
 */
final class SeatAllocator
{
    /**
     * Take seats off a departure and start the clock.
     *
     * @throws NoSeatsAvailable
     */
    public function hold(
        Departure $departure,
        int $seats,
        ?Booking $booking = null,
        ?CarbonInterface $expiresAt = null,
    ): SeatHold {
        if ($seats < 1) {
            throw new \InvalidArgumentException('A hold must be for at least one seat.');
        }

        return DB::transaction(function () use ($departure, $seats, $booking, $expiresAt): SeatHold {
            $locked = $this->lock($departure);

            $this->reclaimLapsedOn($locked);

            if ($locked->capacity_total < 1) {
                throw NoSeatsAvailable::becauseCapacityIsUnset($locked);
            }

            $remaining = $locked->capacity_total - $locked->capacity_held - $locked->capacity_confirmed;

            if ($seats > $remaining) {
                throw NoSeatsAvailable::on($locked, $seats, max(0, $remaining));
            }

            $this->setCounters($locked, held: $locked->capacity_held + $seats);

            $hold = $locked->seatHolds()->create([
                'booking_id' => $booking?->getKey(),
                'seats' => $seats,
                'expires_at' => $expiresAt ?? now()->addMinutes((int) config('booking.holds.minutes', 15)),
            ]);

            // The caller is holding a stale copy of the row this method just
            // changed. Handing it back correct is cheaper than every caller
            // remembering to refresh it.
            $departure->setRawAttributes($locked->getAttributes(), sync: true);

            return $hold;
        });
    }

    /**
     * The seats are paid for: move them from held to confirmed.
     *
     * Idempotent, because a payment callback may arrive twice.
     *
     * The interesting case is a hold that lapsed at 15:00 and a payment that
     * landed at 15:01. The seats went back and somebody else may have taken
     * them, so they are requested again here — and this may legitimately
     * throw. That is the truth of the situation, and it is far better found
     * here, where the money can be refunded, than at check-in.
     *
     * @throws NoSeatsAvailable
     */
    public function confirm(SeatHold $hold): void
    {
        DB::transaction(function () use ($hold): void {
            $locked = $this->lock($hold->departure);

            $this->reclaimLapsedOn($locked);
            $hold->refresh();

            if ($hold->confirmed_at !== null) {
                return;
            }

            if ($hold->released_at === null) {
                // Still held. Held and confirmed move by the same amount, so
                // the total taken never changes and the constraint holds
                // throughout.
                $this->setCounters(
                    $locked,
                    held: max(0, $locked->capacity_held - $hold->seats),
                    confirmed: $locked->capacity_confirmed + $hold->seats,
                );
            } else {
                $remaining = $locked->capacity_total - $locked->capacity_held - $locked->capacity_confirmed;

                if ($hold->seats > $remaining) {
                    throw NoSeatsAvailable::on($locked, $hold->seats, max(0, $remaining));
                }

                $this->setCounters($locked, confirmed: $locked->capacity_confirmed + $hold->seats);
            }

            $hold->forceFill([
                'confirmed_at' => now(),
                'released_at' => $hold->released_at ?? now(),
                'released_reason' => SeatHold::CONFIRMED,
            ])->save();
        });
    }

    /** Give held seats back — the customer abandoned the booking, or staff cancelled it. */
    public function release(SeatHold $hold, string $reason = SeatHold::CANCELLED): void
    {
        DB::transaction(function () use ($hold, $reason): void {
            $locked = $this->lock($hold->departure);
            $hold->refresh();

            if ($hold->released_at !== null) {
                return;
            }

            $this->setCounters($locked, held: max(0, $locked->capacity_held - $hold->seats));

            $hold->forceFill(['released_at' => now(), 'released_reason' => $reason])->save();
        });

        $this->offerToWaitlist($hold->departure);
    }

    /**
     * A confirmed booking is cancelled: the seats go back to the pool.
     *
     * Separate from {@see release()} because confirmed seats are not held
     * seats, and the two counters must not be confused — subtracting a
     * cancelled booking from `capacity_held` would leave `capacity_confirmed`
     * permanently overstated and the departure permanently, invisibly full.
     */
    public function releaseConfirmed(Booking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            $locked = $this->lock($booking->departure);

            $this->setCounters($locked, confirmed: max(0, $locked->capacity_confirmed - $booking->seats));
        });

        $this->offerToWaitlist($booking->departure);
    }

    /**
     * Put lapsed holds back on one departure. Returns the number of seats
     * recovered.
     *
     * Public for the `bookings:expire-holds` command; the booking path calls
     * the locked version below and does not need it.
     */
    public function reclaim(Departure $departure): int
    {
        $seats = DB::transaction(fn (): int => $this->reclaimLapsedOn($this->lock($departure)));

        if ($seats > 0) {
            $this->offerToWaitlist($departure);
        }

        return $seats;
    }

    /**
     * Seats came back: tell the waiting list.
     *
     * Deliberately **not** called from the reclaim inside {@see hold()}.
     * That reclaim runs because somebody is in the middle of taking those
     * seats, and offering them to the waiting list at that moment would take
     * them out from under the customer who triggered it.
     *
     * Called after the transaction rather than inside it, so a release is
     * durable before anything is offered — and resolved from the container
     * rather than injected, because Waitlist depends on this class and a
     * constructor cycle would be the price of a tidier signature.
     */
    private function offerToWaitlist(Departure $departure): void
    {
        app(Waitlist::class)->offerAvailableSeats($departure->fresh() ?? $departure);
    }

    /**
     * Re-read the departure with a row lock held for the rest of the
     * transaction.
     *
     * `lockForUpdate()` is a no-op on SQLite, which is the whole reason the
     * MySQL job exists in CI: a concurrency guarantee that is only ever
     * exercised on an engine that cannot express it is not a guarantee.
     */
    private function lock(Departure $departure): Departure
    {
        return Departure::query()
            ->whereKey($departure->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Expects `$locked` to already be locked by {@see lock()}.
     *
     * @return int seats recovered
     */
    private function reclaimLapsedOn(Departure $locked): int
    {
        // SeatHold::query(), not $locked->seatHolds(): a local scope resolves
        // on a builder typed for this model, and a relationship's builder is
        // typed for the base Model, which has no lapsed(). The same reason
        // PackageComparisonController names its generic.
        $lapsed = SeatHold::query()
            ->where('departure_id', $locked->getKey())
            ->lapsed()
            ->get();

        if ($lapsed->isEmpty()) {
            return 0;
        }

        $seats = (int) $lapsed->sum('seats');

        SeatHold::whereKey($lapsed->modelKeys())->update([
            'released_at' => now(),
            'released_reason' => SeatHold::EXPIRED,
            'updated_at' => now(),
        ]);

        $this->setCounters($locked, held: max(0, $locked->capacity_held - $seats));

        // The bookings those holds belonged to are no longer holding
        // anything, and must not go on saying that they are.
        foreach ($lapsed as $hold) {
            $booking = $hold->booking;

            if ($booking?->status === Booking::HELD) {
                // No actor: nobody did this, the clock did.
                $booking->transitionTo(Booking::EXPIRED, 'The seat hold lapsed before payment.');
            }
        }

        return $seats;
    }

    /**
     * Write the counters through the locked instance.
     *
     * Named arguments with nulls meaning "leave alone", so a caller changing
     * one counter cannot accidentally write a stale value into the other —
     * which is the bug that would make the whole lock pointless.
     */
    private function setCounters(Departure $locked, ?int $held = null, ?int $confirmed = null): void
    {
        $locked->forceFill(array_filter([
            'capacity_held' => $held,
            'capacity_confirmed' => $confirmed,
        ], fn (?int $value): bool => $value !== null))->save();
    }
}
