<?php

namespace Modules\Umrah\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Database\Eloquent\Model;
use Modules\Umrah\Models\Departure;

class DeparturePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'departure';
    }

    /**
     * Only a departure nobody has booked — Hijaz parity, companies plan
     * Phase 3a. One with any booking, whatever its state, is cancelled
     * instead, so its money and history stay. The model refuses as well
     * ({@see Departure::booted()}), because the Super Admin grant answers
     * before this policy is ever asked.
     */
    public function delete(User $user, ?Model $record = null): bool
    {
        if ($record instanceof Departure && $record->bookings()->exists()) {
            return false;
        }

        return $this->resolveAbility($user, 'delete', $record);
    }
}
