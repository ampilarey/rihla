<?php

namespace App\Services\Payments;

/**
 * Something money can be paid against — §15.4 (Phase 9.3).
 *
 * A payment has been polymorphic since Phase 8.6, but until now
 * `bookings.paid_minor` was the only cached total in the schema, so
 * {@see Ledger} refused anything that was not a booking and said so in as
 * many words: *"Give that type its own cached total before sending its
 * money through here."* A stay has one now, and this is that contract.
 *
 * Two methods rather than a bare marker interface. The Ledger writes a
 * total and denominates it, and a model that cannot answer both has no
 * business receiving money — an empty marker would let one through and
 * fail later, on a column, with nothing naming the cause.
 *
 * Implementers must have a `paid_minor` column, or something that behaves
 * like one. That cannot be expressed in PHP, so it is stated here and
 * `PaymentLedgerTest` holds every implementer to it.
 */
interface TakesPayments
{
    /**
     * The currency every payment against this is denominated in.
     *
     * Not the payment's own currency: a stay quoted in USD that somehow
     * received a rufiyaa payment is a problem to surface, not to average.
     */
    public function paymentCurrency(): string;

    /**
     * Store the re-derived total of succeeded payments, in minor units.
     *
     * Called inside the Ledger's row lock, and only from there. It writes
     * rather than returns because the Ledger has no business knowing which
     * column each implementer keeps it in.
     */
    public function storePaidTotal(int $minor): void;
}
