<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * No delete verb: a quotation somebody declined is the record of a price
 * this operator could not win on, which is the most useful row in the
 * table. A sent quotation is superseded rather than edited, so `update`
 * only ever reaches a draft — enforced on the model, not here.
 */
class QuotationPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'quotation';
    }

    /**
     * Putting a price in front of a customer, and taking their answer.
     *
     * Separate from writing one: the number somebody is committed to is
     * not the same decision as the number somebody drafted.
     */
    public function send(User $user): bool
    {
        return $user->can('quotation.send');
    }
}
