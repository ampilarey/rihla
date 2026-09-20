<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * `enquiry.delete` does not exist, so the inherited delete() denies
 * everybody. An enquiry that was lost is the record of a customer this
 * operator did not win, and that is the most useful row in the table.
 */
class EnquiryPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'enquiry';
    }

    /** Handing one to somebody else: a supervisor's call at this size. */
    public function assign(User $user): bool
    {
        return $user->can('enquiry.assign');
    }
}
