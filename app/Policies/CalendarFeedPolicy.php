<?php

namespace App\Policies;

use App\Models\CalendarFeed;
use App\Models\User;
use App\Support\HostContext;
use App\Support\HostRole;

/**
 * Part of a listing — §16 Phase 16. Managed by whoever may edit the listing: in
 * `/staff` that is `property.update`; in `/host` it is the `listings`
 * ability at the host that owns the building (§16.6).
 */
class CalendarFeedPolicy
{
    private function may(User $user, ?CalendarFeed $record = null): bool
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

    public function view(User $user, CalendarFeed $record): bool
    {
        return $this->may($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->may($user);
    }

    public function update(User $user, CalendarFeed $record): bool
    {
        return $this->may($user, $record);
    }

    public function delete(User $user, CalendarFeed $record): bool
    {
        return $this->may($user, $record);
    }
}
