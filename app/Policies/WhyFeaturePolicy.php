<?php

namespace App\Policies;

use App\Models\User;

class WhyFeaturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('whySection.viewAny');
    }

    public function view(User $user): bool
    {
        return $user->can('whySection.view');
    }

    public function create(User $user): bool
    {
        return $user->can('whyFeature.create');
    }

    public function update(User $user): bool
    {
        return $user->can('whyFeature.update');
    }

    public function delete(User $user): bool
    {
        return $user->can('whyFeature.delete');
    }
}
