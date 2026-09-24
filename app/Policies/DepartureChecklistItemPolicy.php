<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * `checklist.*` (§8.3), plus `tick`: marking a line done is a different
 * act from deciding what is on the list.
 */
class DepartureChecklistItemPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'checklist';
    }

    public function tick(User $user): bool
    {
        return $user->can('checklist.tick');
    }
}
