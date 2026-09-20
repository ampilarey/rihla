<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * The same two-person rule as the Knowledge Centre and the Ziyarah Guide
 * (§6.4): `review` is a scholar's judgement about content, `publish` is the
 * office's decision about timing, and nobody holds both.
 *
 * `learning.delete` does not exist. A withdrawn module keeps its reason.
 */
class LearningModulePolicy
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
