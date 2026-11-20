<?php

namespace Modules\Stays\Policies;

use App\Models\User;
use Modules\Stays\Models\PropertyAddon;
use Modules\Stays\Support\HostContext;
use Modules\Stays\Support\HostRole;

/**
 * Part of a listing — §16.14. Managed by whoever may edit the listing: in
 * `/staff` that is `property.update`; in `/host` it is the `listings`
 * ability at the host that owns the building (§16.6).
 */
class PropertyAddonPolicy
{
    private function may(User $user, ?PropertyAddon $record = null): bool
    {
        $host = HostContext::current();

        return $host !== null
            ? HostContext::allows($user, $host, HostRole::LISTINGS, $record)
            : $user->can('property.update');
    }

    public function viewAny(User $user): bool
    {
        return $this->may($user);
    }

    public function view(User $user, PropertyAddon $record): bool
    {
        return $this->may($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->may($user);
    }

    public function update(User $user, PropertyAddon $record): bool
    {
        return $this->may($user, $record);
    }

    public function delete(User $user, PropertyAddon $record): bool
    {
        return $this->may($user, $record);
    }
}
