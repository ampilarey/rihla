<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * `attendance.delete` exists, unlike anything on incidents: a count started
 * against the wrong departure is a mis-click with no evidential value. A
 * record of who was on the coach is not the same kind of thing as a record
 * of what happened to somebody.
 *
 * The Tour Leader does not hold it. A count deleted from the coach is a
 * count nobody can check.
 */
class RollCallPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'attendance';
    }
}
