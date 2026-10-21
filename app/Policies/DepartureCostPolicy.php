<?php

namespace App\Policies;

use App\Models\DepartureCost;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * No delete verb. A cost somebody entered and then thought better of is
 * corrected, not removed: a margin that changed because a row vanished is
 * one nobody can explain afterwards.
 */
class DepartureCostPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'cost';
    }

    /**
     * A settled departure's books are closed: nothing on it is edited or
     * removed, whoever asks. Settling is the point at which the figures
     * stop moving — batch C from Hijaz.
     */
    public function update(User $user, ?Model $record = null): bool
    {
        if ($record instanceof DepartureCost && $record->departure?->isSettled()) {
            return false;
        }

        return $user->can('cost.update');
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        if ($record instanceof DepartureCost && $record->departure?->isSettled()) {
            return false;
        }

        return $user->can('cost.delete');
    }
}
