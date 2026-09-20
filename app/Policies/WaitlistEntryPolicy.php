<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * Deliberately maps onto `booking.*` rather than introducing `waitlist.*`.
 *
 * A waiting list is booking work: the people who take bookings manage the
 * queue, the people who read bookings read it, and nobody's job at Rihla is
 * "waiting list but not bookings". A separate permission set would be six
 * more strings for somebody to assign, and the first time they forgot, the
 * queue would be invisible to the staff who need it.
 */
class WaitlistEntryPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'booking';
    }
}
