<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/** Flights and ground transport share `logistics.*` (§8.3). */
class DepartureTransferPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'logistics';
    }
}
