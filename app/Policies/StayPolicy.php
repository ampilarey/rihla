<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;
use App\Support\HostRole;

class StayPolicy
{
    use ChecksPermissions;

    /**
     * A host's desk — §16.6. Everybody on the team runs bookings; nobody
     * deletes one, for the reason the staff board gives: a stay is a
     * commercial record and ends as a status, never a missing row.
     */
    protected function hostAbility(string $action): ?string
    {
        return $action === 'delete' ? null : HostRole::BOOKINGS;
    }

    protected function resource(): string
    {
        return 'stay';
    }
}
