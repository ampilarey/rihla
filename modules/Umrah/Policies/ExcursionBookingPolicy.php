<?php

namespace Modules\Umrah\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * A family's places on an additional trip are a booking's business
 * (companies plan, Phase 3e): `booking.*`. Never deleted by anybody — places
 * somebody may have paid for are cancelled, as a booking is.
 */
class ExcursionBookingPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'booking';
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        return false;
    }
}
