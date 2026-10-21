<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * `bus.assign` and `bus.checkIn` are not resource actions, so they are
 * checked where the buttons are; the five here are the resource's.
 */
class BusGroupPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'bus';
    }
}
