<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * `payment.delete` does not exist, so the inherited delete() denies
 * everybody. A record of money received that somebody can remove is not a
 * record — and a refund is a new row, so nothing is ever lost by keeping
 * them all.
 */
class PaymentPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'payment';
    }

    /**
     * Pulling the slip, which is a different disclosure from seeing that a
     * payment exists. A transfer slip carries an account number, a name and
     * often a balance.
     */
    public function download(User $user): bool
    {
        return $user->can('payment.download');
    }

    /**
     * Deciding the money is actually in.
     *
     * Separate from `update` on purpose. Booking Staff records what a
     * customer says on the phone; saying it has arrived is a finance
     * decision by a different person, and collapsing the two is how an
     * unchecked slip becomes a confirmed booking.
     */
    public function reconcile(User $user): bool
    {
        return $user->can('payment.reconcile');
    }

    public function refund(User $user): bool
    {
        return $user->can('payment.refund');
    }
}
