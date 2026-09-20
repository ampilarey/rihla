<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Publishing is separate from writing.
 *
 * A tour leader drafts what the families should hear; the office decides it
 * goes in front of them. An announcement that reaches forty households
 * cannot be recalled, and that is a different decision from typing it.
 */
class AnnouncementPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'announcement';
    }

    public function publish(User $user): bool
    {
        return $user->can('announcement.publish');
    }
}
