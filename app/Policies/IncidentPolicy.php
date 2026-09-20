<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * `incident.delete` does not exist, so the inherited delete() denies every
 * role that is checked against a permission. Super Admin still reaches it,
 * because `Gate::before` grants that role every ability outright — by
 * design, so a permission added later cannot lock out the one role that
 * must never be locked out.
 *
 * So this is not a database-level guarantee, and it is not claimed as one:
 * the protection is that no permission exists to grant, no other role could
 * take it, and no admin screen offers the action. An incident report that
 * can be removed is evidence that can be removed — the same reasoning that
 * kept `delete` off visa applications and Nusuk permits.
 */
class IncidentPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'incident';
    }

    /** Handing it to somebody: the office's call, not the coach's. */
    public function assign(User $user): bool
    {
        return $user->can('incident.assign');
    }

    /**
     * Saying it is over.
     *
     * Separate from `update` on purpose. A tour leader adds to the
     * narrative all week; deciding a serious incident is closed is a
     * different act, and a leader closing their own from the coach is how
     * one stops being followed up.
     */
    public function resolve(User $user): bool
    {
        return $user->can('incident.resolve');
    }
}
