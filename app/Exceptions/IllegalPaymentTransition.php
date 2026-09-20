<?php

namespace App\Exceptions;

use App\Models\Payment;
use RuntimeException;

class IllegalPaymentTransition extends RuntimeException
{
    public static function from(Payment $payment, string $to): self
    {
        $allowed = Payment::TRANSITIONS[$payment->status] ?? [];

        return new self(sprintf(
            'A %s payment cannot become %s. Allowed from here: %s.',
            $payment->status,
            $to,
            $allowed === []
                ? 'nothing — it is final, and money that turns out not to have arrived is reversed by a refund, not un-succeeded'
                : implode(', ', $allowed),
        ));
    }
}
