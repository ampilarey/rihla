<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Neither `notice.create` nor `notice.delete` exists, so the inherited
 * methods deny every role checked against a permission.
 *
 * That is the intent. A notice is raised from a record that already exists;
 * one somebody typed would be a claim nothing backs, and one somebody
 * deleted would be a chase that quietly stopped.
 */
class NoticePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'notice';
    }

    /** Saying it has been dealt with. */
    public function handle(User $user): bool
    {
        return $user->can('notice.handle');
    }
}
