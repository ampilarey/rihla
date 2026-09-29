<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;
use App\Support\HostRole;

class ReviewPolicy
{
    use ChecksPermissions;

    /**
     * In `/host` a host reads and replies to their own reviews (§16.6, the
     * `reviews` ability); `update` there means *reply*. Nobody deletes a
     * review — a hidden one is kept, the record of what was said.
     */
    protected function hostAbility(string $action): ?string
    {
        return in_array($action, ['viewAny', 'view', 'update'], true) ? HostRole::REVIEWS : null;
    }

    protected function resource(): string
    {
        return 'review';
    }
}
