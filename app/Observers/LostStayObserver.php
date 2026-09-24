<?php

namespace App\Observers;

use App\Models\Stay;
use App\Services\Stays\LostStayFollowUp;
use App\Services\Stays\StayBooking;

/**
 * Catches a stay falling through, wherever it happens — §15.7.
 *
 * An observer rather than a call in {@see StayBooking}
 * because a stay is lost by **three** different routes: a member of staff
 * pressing Decline, the allocator expiring a lapsed hold inside its row
 * lock, and the `stays:expire-holds` command sweeping a quiet room.
 * Threading a call through each is three places to remember, and the
 * fourth route somebody adds next year is the one that silently drops a
 * customer.
 *
 * ## `updated`, not `saved`
 *
 * `AGENTS.md` records why: `wasChanged()` is false on an insert, because
 * Laravel populates `$changes` in `performUpdate` and not on create. A
 * single `saved()` handler would miss every first write — and here it
 * would also fire for a factory building a declined stay in a test
 * fixture, which is not a customer anybody should ring.
 */
class LostStayObserver
{
    public function updated(Stay $stay): void
    {
        if (! $stay->wasChanged('status')) {
            return;
        }

        app(LostStayFollowUp::class)->record($stay);
    }
}
