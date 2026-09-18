<?php

namespace App\Policies;

use App\Models\User;

/**
 * Settings are a single keyed store, not a collection, so there is nothing to
 * list, create or delete — only to read and to change.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('setting.view');
    }

    public function view(User $user): bool
    {
        return $user->can('setting.view');
    }

    public function update(User $user): bool
    {
        return $user->can('setting.update');
    }
}
