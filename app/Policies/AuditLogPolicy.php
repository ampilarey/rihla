<?php

namespace App\Policies;

use App\Models\User;

/**
 * Read-only by design. There is no create, update or delete, because an
 * audit trail anyone can edit proves nothing — entries are written by the
 * observer and never touched again.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.viewAny');
    }

    public function view(User $user): bool
    {
        return $user->can('audit.viewAny');
    }
}
