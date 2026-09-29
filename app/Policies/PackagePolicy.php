<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;
use App\Support\HostRole;

class PackagePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'package';
    }

    /**
     * In `/host`, a host's own packages are the `listings` ability's — the
     * same people who write the listings they are built on (§16 Phase 16).
     * Whether a live package may still be changed is the resource's rule.
     */
    protected function hostAbility(string $action): ?string
    {
        return HostRole::LISTINGS;
    }
}
