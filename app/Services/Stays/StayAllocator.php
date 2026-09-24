<?php

namespace App\Services\Stays;

use App\Exceptions\RoomNotAvailable;
use App\Models\RoomType;
use App\Models\Stay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only thing in this application that takes a room off the calendar.
 *
 * ## Why it is written this way
 *
 * Two families open the same guesthouse on the same December week, and the
 * property has one twin room. Both read "available". Both press book.
 * Without serialisation both pass the availability check — each reads a
 * world in which the other's stay does not yet exist — and both are told
 * their room is held. Nobody finds out until two couples arrive at one
 * door on an island with no second guesthouse.
 *
 * §15.4 requires this prevented at the database. The mechanism is a
 * `SELECT … FOR UPDATE` on the **room type** row: not because that row is
 * being changed, but because it is the one thing every competing booking
 * for this room has in common. The second request blocks until the first
 * has committed, then re-reads a world its stay is in.
 *
 * ## There is no second guard, and that is the important part
 *
 * A departure has a database CHECK underneath its row lock —
 * `capacity_held + capacity_confirmed <= capacity_total` — so a code path
 * that forgets to lock still cannot oversell. **Nothing equivalent is
 * possible here.** The rule spans rows: "for every night in this range, how
 * many other stays overlap it". A CHECK constraint sees one row and cannot
 * count its neighbours, and no index can express it either.
 *
 * So the lock in {@see lock()} is not the first of two defences. It is the
 * only one. Two consequences follow, and both are load-bearing:
 *
 * 1. **Every write that takes dates must come through this class.** A
 *    console command, an import, or a Filament action that creates a
 *    `held` stay directly bypasses the guarantee entirely and nothing will
 *    report it.
 * 2. **`StayLockTest` is the guarantee, not a confirmation of it.** It runs
 *    only on MySQL, because `lockForUpdate()` is a silent no-op on SQLite —
 *    every single-threaded test here would pass identically with the lock
 *    deleted.
 *
 * ## Expiry is never trusted to cron
 *
 * A hold nobody paid for must give the dates back. Doing that only from a
 * scheduled command means that on a host where cron is misconfigured — and
 * this is cPanel shared hosting — a guesthouse reads as full while it is
 * empty, for ever. So lapsed holds are reclaimed *inside the same row lock*
 * the next hold takes. A command tidying up quiet rooms is a convenience,
 * not the mechanism.
 */
class StayAllocator
{
    public function __construct(private readonly Availability $availability) {}

    /**
     * Take the dates: the partner has said yes, and the deposit clock starts.
     *
     * The availability check happens **inside** the lock, deliberately. The
     * same check on the page outside it is a quote — true when it was read,
     * and not a promise. This one is a decision.
     *
     * @throws RoomNotAvailable
     */
    public function hold(Stay $stay, ?CarbonInterface $expiresAt = null): Stay
    {
        return DB::transaction(function () use ($stay, $expiresAt): Stay {
            $room = $this->lock($stay->roomType);

            $this->reclaimLapsedOn($room);

            $stay->refresh();

            // Already holding these dates: nothing to take, and re-checking
            // would find the stay competing with itself.
            if ($stay->status === Stay::HELD) {
                return $stay;
            }

            $this->availability->assertAvailable($room, $stay->check_in, $stay->check_out, $stay);

            $stay->forceFill([
                'partner_confirmed_at' => $stay->partner_confirmed_at ?? now(),
                'expires_at' => $expiresAt ?? now()->addHours((int) config('stays.holds.hours', 24)),
            ])->save();

            $stay->transitionTo(Stay::HELD);

            return $stay;
        });
    }

    /**
     * The deposit landed: the dates are theirs.
     *
     * Idempotent, because a payment callback may arrive twice.
     *
     * The interesting case is a hold that lapsed at 09:00 and a deposit
     * that landed at 09:01. The dates went back and somebody else may have
     * taken them, so they are asked for again here — and this may
     * legitimately throw. That is the truth of the situation, and far
     * better found here, where the money can be refunded, than at a
     * guesthouse door.
     *
     * @throws RoomNotAvailable
     */
    public function confirm(Stay $stay): Stay
    {
        return DB::transaction(function () use ($stay): Stay {
            $room = $this->lock($stay->roomType);

            $this->reclaimLapsedOn($room);

            $stay->refresh();

            if ($stay->status === Stay::CONFIRMED) {
                return $stay;
            }

            // A hold that lapsed while the money was in flight is now
            // `expired`, which is final — so this is a new ask for the same
            // dates, and it has to pass the same check anybody else would.
            if ($stay->status !== Stay::HELD) {
                $this->availability->assertAvailable($room, $stay->check_in, $stay->check_out, $stay);
            }

            $stay->forceFill(['expires_at' => null])->save();
            $stay->transitionTo(Stay::CONFIRMED);

            return $stay;
        });
    }

    /**
     * Give the dates back — the customer cancelled, or staff did.
     *
     * Takes the lock even though it only frees dates. Without it, a release
     * committing between another request's availability read and its write
     * would leave that request refusing a room that is, by the time it
     * answers, free.
     */
    public function release(Stay $stay, string $status = Stay::CANCELLED, ?string $reason = null): Stay
    {
        return DB::transaction(function () use ($stay, $status, $reason): Stay {
            $this->lock($stay->roomType);

            $stay->refresh();

            if (! $stay->isOccupying() && $stay->status !== Stay::REQUESTED) {
                return $stay;
            }

            $stay->forceFill(['expires_at' => null])->save();
            $stay->transitionTo($status, $reason);

            return $stay;
        });
    }

    /** The partner said no. Distinct from a cancellation: nobody changed their mind. */
    public function decline(Stay $stay, ?string $reason = null): Stay
    {
        return $this->release($stay, Stay::DECLINED, $reason);
    }

    /**
     * Put lapsed holds on one room back on the calendar.
     *
     * Public for the `stays:expire-holds` command. The hold path calls the
     * locked version below and does not need it.
     *
     * @return int stays expired
     */
    public function reclaim(RoomType $room): int
    {
        return DB::transaction(fn (): int => $this->reclaimLapsedOn($this->lock($room)));
    }

    /**
     * Re-read the room type with a row lock held for the rest of the
     * transaction.
     *
     * `lockForUpdate()` is a no-op on SQLite, which is the entire reason the
     * MySQL job exists in CI: a concurrency guarantee exercised only on an
     * engine that cannot express it is not a guarantee.
     */
    private function lock(RoomType $room): RoomType
    {
        return RoomType::query()
            ->whereKey($room->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Expects `$room` to already be locked by {@see lock()}.
     *
     * @return int stays expired
     */
    private function reclaimLapsedOn(RoomType $room): int
    {
        $lapsed = Stay::query()
            ->where('room_type_id', $room->getKey())
            ->lapsed()
            ->get();

        foreach ($lapsed as $stay) {
            // Through the status machine rather than a bulk update: expiry
            // is a transition like any other, and a mass `update()` would
            // skip the one place that validates them.
            $stay->forceFill(['expires_at' => null])->save();
            $stay->transitionTo(Stay::EXPIRED);
        }

        return $lapsed->count();
    }
}
