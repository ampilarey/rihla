<?php

namespace App\Policies;

use App\Models\User;

/**
 * Why-sections are fixed slots on the homepage: they are edited, never
 * created or destroyed, so this policy deliberately has no create or delete.
 */
class WhySectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('whySection.viewAny');
    }

    public function view(User $user): bool
    {
        return $user->can('whySection.view');
    }

    public function update(User $user): bool
    {
        return $user->can('whySection.update');
    }
}
