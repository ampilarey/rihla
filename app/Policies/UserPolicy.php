<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Who may manage staff accounts.
 *
 * Only Super Admin holds `user.*`, and it reaches them through `Gate::before`
 * rather than by listing the permissions — granting staff management to a role
 * is a decision for whoever needs it, not a default. A role that can grant
 * roles can grant its own.
 *
 * Deliberately no extra rules here. `Gate::before` returns true for Super
 * Admin before any policy method runs, so a guard written in this class would
 * never execute for the only people who can reach these screens — which is
 * exactly how a rule ends up looking enforced and not being. The two rules
 * that matter live where they cannot be skipped:
 *
 * - **The last Super Admin cannot be deleted** — a `deleting` event on the
 *   User model, so it holds for the panel, the profile page and tinker alike.
 * - **Nobody deletes their own account from the staff screen** — the delete
 *   actions hide themselves for the signed-in user. Deleting your own account
 *   from your own profile page is a different, deliberate feature.
 */
class UserPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'user';
    }
}
