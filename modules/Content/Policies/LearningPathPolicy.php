<?php

namespace Modules\Content\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Modules\Content\Models\LearningPath;

/**
 * A path is an ordering of modules a scholar has already signed off, so it
 * carries no review of its own — see {@see LearningPath}.
 * Publishing one is an office act, under the same `learning.publish` the
 * modules use.
 */
class LearningPathPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'learning';
    }

    public function review(User $user): bool
    {
        return $user->can('learning.review');
    }

    public function publish(User $user): bool
    {
        return $user->can('learning.publish');
    }
}
