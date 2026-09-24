<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * What a way of taking money has to be able to do.
 *
 * The plan's §5.3 requires provider independence: the chain is
 * `payable → Payment → PaymentTransaction → provider driver`, and a gateway
 * appears in exactly one class. The payable was a Booking until §15.4
 * (Phase 9.3) gave a guesthouse stay a deposit to collect; it is now
 * anything implementing {@see TakesPayments}, which is what says where the
 * cached total lands. Everything above this interface — the
 * booking screen, the ledger, the staff reconciliation — is written once and
 * does not change when BML is connected.
 *
 * A driver **opens** a payment and says what the customer has to do. It
 * never touches a cached paid total — that belongs to {@see Ledger} under a
 * row lock — and it never decides on its own that money has arrived.
 *
 * Handling a gateway callback is **not** in this interface. A bank transfer
 * and cash have no callbacks, and putting a `settle()` here would mean two
 * of the three drivers carrying a method that can only throw or lie about
 * what it did. Gateways that do call back implement
 * {@see HandlesCallbacks} as well.
 */
interface PaymentGateway
{
    /** The key this driver is configured under, e.g. 'bank_transfer'. */
    public function key(): string;

    /**
     * Whether this way of paying can be offered right now.
     *
     * False is a real answer and is said out loud rather than hidden: a
     * card button that 500s is worse than a page saying cards are not
     * connected yet.
     */
    public function isAvailable(): bool;

    /**
     * What a customer has to do to pay this way, in their language.
     *
     * Returns null when there is nothing to say. Never invents a detail it
     * does not have — an account number nobody has given is not a
     * placeholder, it is an instruction to send money somewhere.
     *
     * @return array<string, string|null>
     */
    public function instructions(Money $amount): array;

    /**
     * Open a payment against this thing. Records the intent; moves no money.
     *
     * `$payable` is untyped in the signature rather than declared
     * `Model&TakesPayments`: PHP has no intersection type usable in a
     * parameter position across every version this runs on, and widening it
     * to `Model` alone would let through a model with nowhere to put a
     * total. The docblock carries the real type, static analysis enforces
     * it, and {@see Ledger::payableFor()} refuses at runtime.
     *
     * @param  Model&TakesPayments  $payable
     * @param  array<string, mixed>  $details
     */
    public function start($payable, Money $amount, array $details = []): Payment;
}
