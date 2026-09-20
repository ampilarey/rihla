<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * A task does have a delete verb, unlike most things here: a reminder
 * typed by mistake is not a record of anything, and leaving it on
 * somebody's list for ever teaches them to ignore the list.
 */
class CrmTaskPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'task';
    }

    /** Work everybody can hand around is work nobody owns. */
    public function assign(User $user): bool
    {
        return $user->can('task.assign');
    }
}
