<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * A gateway that tells us what happened, rather than waiting for a person to
 * look.
 *
 * Separate from {@see PaymentGateway} because most of Rihla's money does not
 * arrive this way. A bank transfer is settled by somebody reading a slip and
 * a statement; giving those drivers a callback method would mean two classes
 * carrying a method that can only throw.
 *
 * **Idempotency is not this interface's job — it is the database's.**
 * `payment_transactions` has a unique index on
 * `(provider, provider_event_id)`, so the same webhook delivered twice fails
 * the second insert and the money is recorded once. An implementation that
 * tried to check first would have a race between the check and the write.
 */
interface HandlesCallbacks
{
    /**
     * Apply what the provider says happened to this payment.
     *
     * @param  array<string, mixed>  $event
     */
    public function handleCallback(Payment $payment, array $event): void;
}
