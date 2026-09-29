<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;
use App\Support\HostRole;

class PropertyPolicy
{
    use ChecksPermissions;

    /**
     * A host edits their own listings — §16.6 — with the `listings`
     * ability. Removing a listing is Rihla's decision, not theirs.
     */
    protected function hostAbility(string $action): ?string
    {
        return $action === 'delete' ? null : HostRole::LISTINGS;
    }

    protected function resource(): string
    {
        return 'property';
    }
}
