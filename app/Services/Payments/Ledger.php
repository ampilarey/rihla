<?php

namespace App\Services\Payments;

use App\Exceptions\IllegalPaymentTransition;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Booking\SeatAllocator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only thing in this application that moves `bookings.paid_minor`.
 *
 * ## Why it is written this way
 *
 * Two members of staff open the same booking and both reconcile a transfer.
 * Both read `paid_minor = 0`. Without serialisation both write
 * `paid_minor = 0 + 28500`, and a booking that has been paid twice looks
 * like a booking that has been paid once — which is found out by a customer
 * asking where their money went. This is the same hazard as the seat
 * counters [R-2] and it gets the same answer: every read of the total that
 * leads to a write takes `SELECT … FOR UPDATE` on the booking row first.
 *
 * SQLite cannot exercise a row lock, which is why CI also runs the suite
 * against MySQL.
 *
 * ## The total is recomputed, never incremented
 *
 * `paid_minor` is a SUM of succeeded payments, re-derived inside the lock,
 * rather than `paid_minor + amount`. An increment is only correct if every
 * write is perfect for ever; a re-derivation is correct again the moment
 * anything is fixed by hand, and a refund is just a negative row in the
 * same SUM.
 *
 * ## Confirming a booking is not this class's decision
 *
 * Reconciling a payment does not confirm a booking. A deposit is not a
 * balance, the seats may have lapsed in the meantime, and
 * {@see SeatAllocator} owns whether they can still be
 * had. This records money; a person decides what it means.
 */
final class Ledger
{
    /**
     * Mark money as actually received, and re-derive the booking's total.
     *
     * @throws IllegalPaymentTransition
     */
    public function reconcile(Payment $payment, ?string $note = null, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $note, $actor): Payment {
            // The lock before the read. Taking it after would make the
            // recomputation below a snapshot of a total somebody else is
            // already changing.
            $this->lock($this->bookingKeyFor($payment));

            $payment->transitionTo(Payment::SUCCEEDED, $note, $actor);

            $this->recompute($this->bookingKeyFor($payment));

            return $payment->refresh();
        });
    }

    /**
     * Refuse a payment: the slip was not what it claimed, or the gateway
     * declined it.
     *
     * The reason is required by the caller, not here, because the useful
     * one is specific — "the slip is for MVR 2,850, the booking is MVR
     * 28,500" — and a required-but-empty field just gets a full stop typed
     * into it.
     */
    public function refuse(Payment $payment, string $reason, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actor): Payment {
            $this->lock($this->bookingKeyFor($payment));

            $payment->transitionTo(Payment::FAILED, $reason, $actor);

            // A payment that never succeeded contributes nothing to the sum,
            // so this changes no total — unless it is being refused after a
            // hand-edit, in which case the re-derivation is the point.
            $this->recompute($this->bookingKeyFor($payment));

            return $payment->refresh();
        });
    }

    /**
     * Reverse money already received.
     *
     * A new row with a negative amount, pointing at the one it reverses,
     * rather than a status change on the original. The original keeps its
     * date, its reference and its slip — the evidence of what was actually
     * received — and the paid total stays a plain SUM with nothing to
     * special-case.
     */
    public function refund(Payment $payment, ?Money $amount = null, ?string $reason = null, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $actor): Payment {
            $this->lock($this->bookingKeyFor($payment));

            // An explicit check rather than `$amount?->minor ?? …`: `??`
            // already swallows a null property access, so the nullsafe
            // operator is redundant there and static analysis says so. This
            // also reads as what it means — refund what was asked for, or
            // the whole payment.
            $minor = $amount instanceof Money ? $amount->minor : $payment->amount_minor;

            $refund = Payment::create([
                'payable_type' => $payment->payable_type,
                'payable_id' => $payment->payable_id,
                'refund_of_id' => $payment->getKey(),
                'method' => $payment->method,
                'provider' => $payment->provider,
                'currency' => $payment->currency,
                // Negative whichever sign the caller passed: a refund of
                // MVR 500 and a refund of MVR -500 mean the same thing to
                // the person typing it.
                'amount_minor' => -abs($minor),
                'paid_at' => now(),
                'notes' => $reason,
            ]);

            $refund->forceFill(['recorded_by' => ($actor ?? auth()->user())?->getKey()])->save();

            $refund->transactions()->create([
                'type' => PaymentTransaction::CREATED,
                'to_status' => Payment::PENDING,
                'amount_minor' => $refund->amount_minor,
                'user_id' => ($actor ?? auth()->user())?->getKey(),
                'reason' => $reason,
                'created_at' => now(),
            ]);

            // A refund is money that has left, so it is settled at once
            // rather than waiting to be reviewed: the decision to issue it
            // *was* the review.
            $refund->transitionTo(Payment::SUCCEEDED, $reason, $actor);

            $this->recompute($this->bookingKeyFor($payment));

            return $refund->refresh();
        });
    }

    /**
     * Re-derive one booking's paid total from its succeeded payments.
     *
     * Public because a hand-fixed row, an import, or a console command
     * should be able to put the cached total right without going through a
     * reconciliation that did not happen.
     */
    public function recompute(int $bookingId): Money
    {
        return DB::transaction(function () use ($bookingId): Money {
            $booking = $this->lock($bookingId);

            $paid = (int) Payment::query()
                ->where('payable_type', Booking::class)
                ->where('payable_id', $bookingId)
                ->succeeded()
                ->sum('amount_minor');

            $booking->forceFill(['paid_minor' => $paid])->save();

            return Money::ofMinor($paid, $booking->currency);
        });
    }

    /**
     * The booking row, locked for the rest of the transaction.
     *
     * `lockForUpdate()` is a no-op on SQLite, which is why the MySQL job
     * exists in CI: the guarantee this class rests on cannot be proved by
     * the default test database.
     */
    /**
     * The booking whose cached total this payment moves.
     *
     * Loud rather than lenient — §15.3 (Phase 8.6). A payment is
     * polymorphic now, but `bookings.paid_minor` is the only cached total
     * that exists, so money against anything else has nowhere to land.
     * Skipping quietly would leave a stay's total silently wrong from the
     * day Phase 9 ships; this makes that day fail on the first test instead.
     */
    private function bookingKeyFor(Payment $payment): int
    {
        $bookingId = $payment->bookingKey();

        if ($bookingId === null) {
            throw new LogicException(sprintf(
                'Ledger maintains bookings.paid_minor, and this payment is against %s. '
                .'Give that type its own cached total before sending its money through here.',
                (string) $payment->payable_type,
            ));
        }

        return $bookingId;
    }

    private function lock(int $bookingId): Booking
    {
        /** @var Booking */
        return Booking::whereKey($bookingId)->lockForUpdate()->firstOrFail();
    }
}
