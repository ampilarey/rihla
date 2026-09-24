<?php

namespace App\Services\Payments\Drivers;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentGateway;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;

/**
 * Bank transfer — how most of this money will actually arrive.
 *
 * §5.3 is explicit that this is not an afterthought. An operator that treats
 * bank transfer as the fallback behind a card button builds a checkout most
 * of its Maldivian customers cannot use.
 *
 * **It has no callback and no automation**, and that is honest rather than
 * unfinished: the money lands in a bank account, somebody reads a statement,
 * and somebody decides it matches. What this class does is record the claim
 * and carry the slip, so that the decision is made against evidence instead
 * of a phone call somebody half-remembers.
 */
final class BankTransfer implements PaymentGateway
{
    public function key(): string
    {
        return 'bank_transfer';
    }

    public function isAvailable(): bool
    {
        return (bool) config('payments.methods.bank_transfer.enabled', false);
    }

    /**
     * Where to send it — or, when nobody has said, an admission.
     *
     * **The account number is not invented.** config/payments.php ships
     * empty, and while it is empty this returns a null account and the page
     * tells the customer to ask. A made-up account number is not a
     * placeholder: it is an instruction to send money somewhere.
     *
     * @return array<string, string|null>
     */
    public function instructions(Money $amount): array
    {
        $accounts = (array) config('payments.bank.accounts', []);

        return [
            'bank' => config('payments.bank.name'),
            'account_name' => config('payments.bank.account_name'),
            'account' => $accounts[$amount->currency] ?? null,
            'amount' => $amount->format(),
        ];
    }

    /** Whether there is enough on file to tell a customer where to send money. */
    public function hasAccountFor(string $currency): bool
    {
        $accounts = (array) config('payments.bank.accounts', []);

        return filled($accounts[strtoupper($currency)] ?? null);
    }

    /**
     * Record that somebody intends to pay this way.
     *
     * Starts at `pending`, not `awaiting_review`: nothing is waiting on
     * anybody until a slip or a claim arrives. The customer's own words
     * about the transfer are kept verbatim — a Maldivian bank reference is
     * whatever the sender typed into the box, and normalising it would lose
     * the one thing that matches it against a statement.
     *
     * @param  array<string, mixed>  $details
     */
    public function start(Booking $booking, Money $amount, array $details = []): Payment
    {
        $payment = Payment::create([
            'payable_type' => $booking->getMorphClass(),
            'payable_id' => $booking->getKey(),
            'method' => Payment::BANK_TRANSFER,
            'provider' => null,
            'currency' => $amount->currency,
            'amount_minor' => $amount->minor,
            'paid_at' => $details['paid_at'] ?? null,
            'payer_name' => $details['payer_name'] ?? null,
            'payer_bank' => $details['payer_bank'] ?? null,
            'payer_reference' => $details['payer_reference'] ?? null,
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
