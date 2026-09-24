<?php

namespace App\Services\Payments;

use App\Exceptions\IllegalPaymentTransition;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Booking\SeatAllocator;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only thing in this application that moves a cached paid total —
 * `bookings.paid_minor`, `stays.paid_minor`, and whatever implements
 * {@see TakesPayments} next.
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
            $payable = $this->payableFor($payment);
            $this->lock($payable::class, (int) $payable->getKey());

            $payment->transitionTo(Payment::SUCCEEDED, $note, $actor);

            $this->recompute($payable);

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
            $payable = $this->payableFor($payment);
            $this->lock($payable::class, (int) $payable->getKey());

            $payment->transitionTo(Payment::FAILED, $reason, $actor);

            // A payment that never succeeded contributes nothing to the sum,
            // so this changes no total — unless it is being refused after a
            // hand-edit, in which case the re-derivation is the point.
            $this->recompute($payable);

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
            $payable = $this->payableFor($payment);
            $this->lock($payable::class, (int) $payable->getKey());

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

            $this->recompute($payable);

            return $refund->refresh();
        });
    }

    /**
     * Re-derive one payable's paid total from its succeeded payments.
     *
     * Public because a hand-fixed row, an import, or a console command
     * should be able to put the cached total right without going through a
     * reconciliation that did not happen.
     *
     * Takes the model rather than an id — §15.4 (Phase 9.3). An integer
     * alone stopped being enough the moment a second kind of thing could be
     * paid for: `recompute(7)` cannot say whether 7 is a booking or a stay,
     * and guessing wrong writes one customer's money onto another's record.
     *
     * @param  Model&TakesPayments  $payable
     */
    public function recompute($payable): Money
    {
        return DB::transaction(function () use ($payable): Money {
            $locked = $this->lock($payable::class, (int) $payable->getKey());

            $paid = (int) Payment::query()
                ->where('payable_type', $locked->getMorphClass())
                ->where('payable_id', $locked->getKey())
                ->succeeded()
                ->sum('amount_minor');

            $locked->storePaidTotal($paid);

            return Money::ofMinor($paid, $locked->paymentCurrency());
        });
    }

    /**
     * The thing whose cached total this payment moves.
     *
     * Loud rather than lenient — the rule Phase 8.6 wrote down and this
     * phase inherits. A payment is polymorphic, but only a model that has
     * somewhere to *put* a total may receive money through here. A type
     * that does not implement {@see TakesPayments} is a missing cached
     * total, and skipping it quietly would leave that type's balance
     * silently wrong from the day it ships.
     *
     * @return Model&TakesPayments
     */
    private function payableFor(Payment $payment): Model
    {
        $type = (string) $payment->payable_type;

        // The *string* is checked before the morph is resolved, and that
        // order matters: a payable_type naming a class this application no
        // longer has — a retired model, a typo written straight into the
        // column, a row restored from an older schema — would fatal on
        // resolution with "class not found", which names the symbol and not
        // the problem. Read first, and the refusal can say what is actually
        // wrong and what to do about it.
        if (! is_a($type, TakesPayments::class, allow_string: true)) {
            throw new LogicException(sprintf(
                'A payment is against %s, which does not implement %s. '
                .'Give that type a cached total before sending its money through here.',
                $type,
                TakesPayments::class,
            ));
        }

        $payable = $payment->payable;

        if (! $payable instanceof Model || ! $payable instanceof TakesPayments) {
            throw new LogicException(sprintf(
                'A payment is against %s #%s, which no longer exists.',
                $type,
                (string) $payment->payable_id,
            ));
        }

        return $payable;
    }

    /**
     * The payable's row, locked for the rest of the transaction.
     *
     * `lockForUpdate()` is a no-op on SQLite, which is why the MySQL job
     * exists in CI: the guarantee this class rests on cannot be proved by
     * the default test database.
     *
     * @param  class-string<Model>  $type
     * @return Model&TakesPayments
     */
    private function lock(string $type, int $id): Model
    {
        $locked = $type::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if (! $locked instanceof TakesPayments) {
            throw new LogicException($type.' does not implement '.TakesPayments::class.'.');
        }

        return $locked;
    }
}
