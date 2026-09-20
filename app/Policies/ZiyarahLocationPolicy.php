<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * The same two-person rule as the Knowledge Centre (§6.4): `review` is a
 * scholar's judgement about content, `publish` is the office's decision
 * about timing, and nobody holds both.
 *
 * `ziyarah.delete` does not exist. A withdrawn location keeps its reason.
 */
class ZiyarahLocationPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'ziyarah';
    }

    public function review(User $user): bool
    {
        return $user->can('ziyarah.review');
    }

    public function publish(User $user): bool
    {
        return $user->can('ziyarah.publish');
    }
}
