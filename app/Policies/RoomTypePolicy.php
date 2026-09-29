<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;
use App\Support\HostRole;

class RoomTypePolicy
{
    use ChecksPermissions;

    /**
     * Part of a host's own listing — §16.6 — managed with the `listings`
     * ability, removal included: a room they no longer let, a season that
     * was wrong, a night they have reopened.
     */
    protected function hostAbility(string $action): ?string
    {
        return HostRole::LISTINGS;
    }

    protected function resource(): string
    {
        return 'roomType';
    }
}
