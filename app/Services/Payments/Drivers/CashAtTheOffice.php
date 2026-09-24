<?php

namespace App\Services\Payments\Drivers;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentGateway;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;

/**
 * Money handed over a counter.
 *
 * Kept as a real method rather than left to "notes on the booking", because
 * it happens and because a payment nobody can see in the payments list is a
 * payment nobody can reconcile. There is no slip and no gateway: the
 * evidence is the name of the person who says they took it, which this
 * records.
 */
final class CashAtTheOffice implements PaymentGateway
{
    public function key(): string
    {
        return 'cash';
    }

    public function isAvailable(): bool
    {
        return (bool) config('payments.methods.cash.enabled', false);
    }

    /** @return array<string, string|null> */
    public function instructions(Money $amount): array
    {
        return ['amount' => $amount->format()];
    }

    /** @param  array<string, mixed>  $details */
    public function start($payable, Money $amount, array $details = []): Payment
    {
        $payment = Payment::create([
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'method' => Payment::CASH,
            'currency' => $amount->currency,
            'amount_minor' => $amount->minor,
            'paid_at' => $details['paid_at'] ?? now(),
            'payer_name' => $details['payer_name'] ?? null,
            'notes' => $details['notes'] ?? null,
        ]);

        $payment->forceFill(['recorded_by' => Auth::id()])->save();

        $payment->transactions()->create([
            'type' => PaymentTransaction::CREATED,
            'to_status' => Payment::PENDING,
            'amount_minor' => $payment->amount_minor,
            'user_id' => Auth::id(),
            'created_at' => now(),
        ]);

        return $payment;
    }
}
