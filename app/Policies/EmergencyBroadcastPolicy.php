<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * `broadcast.delete` does not exist. What was sent in an emergency is the
 * record of what was said, and a record that can be removed is not one.
 */
class EmergencyBroadcastPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'broadcast';
    }

    /**
     * Pressing send.
     *
     * Separate from `update` and held only by Operations. This reaches
     * every household on a departure at once and cannot be unsent.
     */
    public function send(User $user): bool
    {
        return $user->can('broadcast.send');
    }
}
