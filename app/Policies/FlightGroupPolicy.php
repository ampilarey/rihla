<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * Flight groups are the buses' own business (companies plan, Phase 3c):
 * whoever may make and fill a bus may make and fill the group it meets,
 * so they read the `bus.*` permissions rather than five new ones nobody
 * would hold apart.
 */
class FlightGroupPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'bus';
    }
}
