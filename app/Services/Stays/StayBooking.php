<?php

namespace App\Services\Stays;

use App\Exceptions\RoomNotAvailable;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Payments\Gateways;
use App\Services\Payments\Ledger;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Turning a set of dates into a stay somebody owes money on — §15.4
 * (Phase 9.3).
 *
 * The flow §15.2 decision 1 and 2 describe, in one place:
 *
 * ```
 * request  →  (a person at Rihla confirms with the partner)  →  held
 *          →  (the deposit lands)                            →  confirmed
 * ```
 *
 * Instant-book properties skip the middle step, because somebody has
 * already given Rihla those rooms in writing.
 *
 * ## What this class is *not*
 *
 * It does not take dates off a calendar — {@see StayAllocator} does that,
 * under the row lock that is the whole no-double-booking guarantee — and it
 * does not decide that money has arrived, which belongs to {@see Ledger}.
 * It composes the two, and owns the arithmetic between them: what the stay
 * costs, what the deposit is, and what the policy printed on the page said.
 *
 * ## The price is frozen at request, not at confirmation
 *
 * A quote is taken when the customer asks and written into
 * `rate_snapshot`. A season edited between the ask and the partner's yes
 * must not move it: the customer agreed to a number, and re-deriving the
 * total later would quietly charge them something they never saw. The same
 * applies to the deposit percentage and the cancellation terms, which is
 * why those live on the property row rather than in a constant.
 */
class StayBooking
{
    public function __construct(
        private readonly Availability $availability,
        private readonly StayAllocator $allocator,
        private readonly Gateways $gateways,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Record what somebody has asked for.
     *
     * Availability is checked here so the customer is told now rather than
     * after a partner has been rung — but it is checked again, inside the
     * lock, when the dates are actually taken. This one is a courtesy; that
     * one is the decision.
     *
     * A request takes no dates. §15.2 decision 1: it costs the customer
     * nothing until a real room is theirs, so it cannot take the room away
     * from anybody else either.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws RoomNotAvailable
     */
    public function request(
        Customer $customer,
        RoomType $room,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $adults = 1,
        int $children = 0,
        array $details = [],
    ): Stay {
        $this->availability->assertAvailable($room, $checkIn, $checkOut);

        $property = $room->property;
        $quote = $this->availability->quote($room, $checkIn, $checkOut);

        return DB::transaction(fn (): Stay => Stay::create([
            'customer_id' => $customer->getKey(),
            'property_id' => $property->getKey(),
            'room_type_id' => $room->getKey(),
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'nights' => $quote->nights(),
            'adults' => $adults,
            'children' => $children,
            'currency' => $quote->currency,
            'rate_snapshot' => $this->snapshotWithPolicy($quote, $property),
            'total_minor' => $quote->total()->minor,
            'deposit_minor' => $quote->deposit($property->deposit_pct)->minor,
            'status' => Stay::REQUESTED,
            'requested_at' => now(),
            'special_requests' => $details['special_requests'] ?? null,
            'source' => $details['source'] ?? null,
        ]));
    }

    /**
     * The partner said yes: take the dates and start the deposit clock.
     *
     * @throws RoomNotAvailable
     */
    public function confirmWithPartner(Stay $stay, ?CarbonInterface $expiresAt = null): Stay
    {
        $stay = $this->allocator->hold($stay, $expiresAt);

        $stay->forceFill(['deposit_due_at' => $stay->expires_at])->save();

        return $stay;
    }

    /** The partner said no. The customer sees the reason. */
    public function decline(Stay $stay, ?string $reason = null): Stay
    {
        return $this->allocator->decline($stay, $reason);
    }

    /**
     * Ask for the deposit.
     *
     * Opens a payment against the **stay** — which is what Phase 8.6 made
     * possible, and the reason payments stopped belonging to bookings
     * alone. The driver records the intent; no money moves here and nothing
     * is confirmed until {@see settle()} is told the money arrived.
     *
     * @param  array<string, mixed>  $details
     */
    public function requestDeposit(Stay $stay, string $method, array $details = []): Payment
    {
        return $this->gateways
            ->for($method)
            ->start($stay, $stay->deposit(), $details);
    }

    /**
     * The money arrived: reconcile it, and confirm the stay if it covers
     * the deposit.
     *
     * Two steps rather than one, and deliberately in this order. The Ledger
     * re-derives the paid total under its own row lock; only then is there
     * a number worth comparing to the deposit. Confirming first and
     * reconciling after would mean a stay confirmed on money that a second
     * look said had not arrived.
     *
     * Reconciling a payment does not by itself confirm anything — the same
     * rule the Ledger states for bookings. A part payment leaves the stay
     * held, the clock still running, and the customer still owing the rest.
     *
     * @throws RoomNotAvailable when the hold lapsed and the dates have gone
     */
    public function settle(Payment $payment, ?string $note = null): Stay
    {
        $this->ledger->reconcile($payment, $note);

        $stay = $payment->payable;

        if (! $stay instanceof Stay) {
            throw new \InvalidArgumentException('That payment is not against a stay.');
        }

        $stay->refresh();

        if (! $stay->depositIsPaid()) {
            return $stay;
        }

        return $this->allocator->confirm($stay);
    }

    /**
     * What is owed, and when.
     *
     * §15.2 decision 2: the balance falls due `balance_days_before` days
     * before check-in, **or immediately** if the stay is inside that window
     * already — somebody booking four days out does not get a due date in
     * the past, which is what subtracting fourteen days blindly would give
     * them.
     */
    public function balanceDueAt(Stay $stay): CarbonInterface
    {
        $days = (int) ($stay->rate_snapshot['policy']['balance_days_before'] ?? 14);

        $due = $stay->check_in->copy()->subDays($days)->startOfDay();

        return $due->isPast() ? now() : $due;
    }

    /**
     * Is cancelling still free?
     *
     * Read from the snapshot, not from the property. The property's terms
     * may have been edited since; the customer agreed to what was on the
     * page on the day, and that is what they are held to.
     */
    public function cancellationIsFree(Stay $stay, ?CarbonInterface $on = null): bool
    {
        $days = (int) ($stay->rate_snapshot['policy']['free_cancel_days'] ?? 14);

        $deadline = $stay->check_in->copy()->subDays($days)->startOfDay();

        return ($on ?? now())->lessThan($deadline);
    }

    /**
     * Freeze the policy alongside the prices.
     *
     * The three numbers are what the page printed, and printing one thing
     * while enforcing another is the defect this whole arrangement exists
     * to avoid. `AGENTS.md` records the migration that moved a stored
     * colour by pointing at a constant; the same shape of mistake here
     * would re-price somebody's cancellation terms months after they
     * agreed to them.
     *
     * @return array<string, mixed>
     */
    private function snapshotWithPolicy(Quote $quote, $property): array
    {
        return [
            ...$quote->snapshot(),
            'policy' => [
                'deposit_pct' => (int) $property->deposit_pct,
                'balance_days_before' => (int) $property->balance_days_before,
                'free_cancel_days' => (int) $property->free_cancel_days,
            ],
        ];
    }

    /** The deposit as money, for a page that has the stay and not the quote. */
    public function depositFor(Stay $stay): Money
    {
        return $stay->deposit();
    }
}
