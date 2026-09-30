<?php

namespace App\Services\Stays;

use App\Exceptions\DeskRefusal;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PropertyUnit;
use App\Models\Stay;
use App\Models\StayCharge;
use App\Models\StayGuest;
use App\Models\User;
use App\Services\Payments\Ledger;
use App\Support\Audience;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What happens at the front desk — §16.10.
 *
 * One service for both panels, so a host checking a guest in and Rihla's
 * staff doing it for them go through the same rules. Every refusal is a
 * {@see DeskRefusal} with a sentence the person at reception can act on.
 */
class StayDesk
{
    /** What a host may add to a bill. The room and Green Tax lines come from the snapshot. */
    public const CHARGE_KINDS = [StayCharge::EXTRA, StayCharge::DISCOUNT, StayCharge::ADJUSTMENT];

    /** How money reaches a host at the property. */
    public const HOST_METHODS = [Payment::CASH, Payment::BANK_TRANSFER, Payment::CARD];

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Put the guests in a room, and write down who they are.
     *
     * The register is the law's (§15.6), so a check-in without a lead guest
     * is refused rather than left to be filled in later — later is when the
     * police ask. The unit is optional: a guesthouse with one room of each
     * kind need not number them.
     *
     * @param  list<array{full_name?: ?string, nationality?: ?string, date_of_birth?: ?string, id_type?: ?string, id_number?: ?string, is_lead?: bool}>  $guests
     *
     * @throws DeskRefusal
     */
    public function checkIn(Stay $stay, array $guests, ?PropertyUnit $unit = null): Stay
    {
        if ($stay->status !== Stay::CONFIRMED) {
            throw new DeskRefusal('Only a confirmed stay can be checked in. This one is '.str_replace('_', ' ', $stay->status).'.');
        }

        $guests = array_values(array_filter($guests, fn (array $guest): bool => filled($guest['full_name'] ?? null)));

        if (count(array_filter($guests, fn (array $guest): bool => (bool) ($guest['is_lead'] ?? false))) !== 1) {
            throw new DeskRefusal('Mark exactly one guest as the lead guest. The register needs somebody answerable for the room.');
        }

        if ($unit !== null) {
            $this->assertUnitFits($stay, $unit);
        }

        return DB::transaction(function () use ($stay, $guests, $unit): Stay {
            foreach ($guests as $guest) {
                $stay->guests()->create([
                    'full_name' => $guest['full_name'],
                    'nationality' => $guest['nationality'] ?? null,
                    'date_of_birth' => $guest['date_of_birth'] ?? null,
                    'id_type' => $guest['id_type'] ?? StayGuest::PASSPORT,
                    'id_number' => $guest['id_number'] ?? null,
                    'is_lead' => (bool) ($guest['is_lead'] ?? false),
                ]);
            }

            $stay->forceFill([
                'unit_id' => $unit?->getKey(),
                'checked_in_at' => now(),
            ])->save();

            $stay->transitionTo(Stay::CHECKED_IN);

            return $stay;
        });
    }

    /**
     * Whether the lead guest's nationality says the stay was sold to the
     * wrong audience — shown to the host, never acted on.
     *
     * The platform does not re-price a stay behind the guest's back. The
     * host sees the mismatch and may add an adjustment, or leave it.
     */
    public function audienceMismatch(Stay $stay): ?string
    {
        $lead = $stay->guests()->lead()->first();

        if ($lead === null || blank($lead->nationality)) {
            return null;
        }

        $maldivian = in_array(mb_strtolower(trim((string) $lead->nationality)), ['mv', 'mdv', 'maldives', 'maldivian'], true);
        $sold = $stay->audience;

        return match (true) {
            $maldivian && $sold === Audience::TOURIST => 'The lead guest is Maldivian, but this stay was priced for a visitor.',
            ! $maldivian && $sold === Audience::LOCAL => 'The lead guest is not Maldivian, but this stay was priced for a citizen.',
            default => null,
        };
    }

    /**
     * They have gone: close the stay and send the room to housekeeping.
     *
     * @throws DeskRefusal
     */
    public function checkOut(Stay $stay): Stay
    {
        if ($stay->status !== Stay::CHECKED_IN) {
            throw new DeskRefusal('Only a guest who is checked in can be checked out.');
        }

        return DB::transaction(function () use ($stay): Stay {
            $stay->forceFill(['checked_out_at' => now()])->save();
            $stay->transitionTo(Stay::COMPLETED);

            $stay->unit?->forceFill(['housekeeping' => PropertyUnit::DIRTY])->save();

            return $stay;
        });
    }

    /**
     * An extra, a discount or an adjustment on the bill.
     *
     * A discount is stored negative whatever sign was typed: "a discount of
     * 20" and "a discount of −20" mean the same thing to the person typing.
     *
     * @throws DeskRefusal
     */
    public function addCharge(Stay $stay, string $kind, string $description, int $quantity, Money $each, ?User $by = null): StayCharge
    {
        if (! in_array($kind, self::CHARGE_KINDS, true)) {
            throw new DeskRefusal('That is not something a bill line can be.');
        }

        if (! in_array($stay->status, [Stay::HELD, Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED], true)) {
            throw new DeskRefusal('Nothing can be charged to a stay that did not happen.');
        }

        if ($each->currency !== $stay->currency) {
            throw new DeskRefusal('A bill is in one currency: this stay is in '.$stay->currency.'.');
        }

        $unit = $kind === StayCharge::DISCOUNT ? -abs($each->minor) : $each->minor;

        return $stay->charges()->create([
            'kind' => $kind,
            'description' => $description,
            'quantity' => max(1, $quantity),
            'unit_minor' => $unit,
            'currency' => $stay->currency,
            'added_by' => $by?->getKey(),
        ]);
    }

    /**
     * Money the host took at the property — §16.9.
     *
     * Recorded as received at once (`succeeded`): the host is telling us
     * what is already in their hand, and there is nothing for anybody else
     * to review. `collected_by = host`, so it counts towards what the guest
     * has paid and **never** towards the deposit that confirms a
     * marketplace stay ({@see Stay::depositIsPaid()}). A refund given at the
     * desk is the same row with the sign turned.
     *
     * @throws DeskRefusal
     */
    public function recordHostPayment(Stay $stay, Partner $host, Money $amount, string $method, ?string $note = null, ?User $by = null, bool $refund = false): Payment
    {
        if (! in_array($method, self::HOST_METHODS, true)) {
            throw new DeskRefusal('Say how the money was paid: cash, bank transfer or card.');
        }

        if ($amount->minor <= 0) {
            throw new DeskRefusal('Enter the amount received.');
        }

        if ($amount->currency !== $stay->currency) {
            throw new DeskRefusal('Record it in '.$stay->currency.', the currency of this stay.');
        }

        if (! in_array($stay->status, [Stay::HELD, Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED], true)) {
            throw new DeskRefusal('This stay is '.str_replace('_', ' ', $stay->status).'; there is nothing to pay for.');
        }

        return DB::transaction(function () use ($stay, $host, $amount, $method, $note, $by, $refund): Payment {
            // Read under a lock on the stay, so two refunds at once cannot
            // both see the same cash in hand (security review, §16).
            Stay::query()->whereKey($stay->getKey())->lockForUpdate()->first();

            if ($refund && $amount->minor > $stay->paidToHost()->minor) {
                throw new DeskRefusal('You can only give back what was paid to you here: '.$stay->paidToHost()->format().'.');
            }

            $payment = $stay->payments()->create([
                'method' => $method,
                'provider' => null,
                'currency' => $amount->currency,
                'amount_minor' => $refund ? -$amount->minor : $amount->minor,
                'paid_at' => now(),
                'notes' => $note,
            ]);

            $payment->forceFill([
                'collected_by' => Payment::COLLECTED_BY_HOST,
                'partner_id' => $host->getKey(),
                'recorded_by' => $by?->getKey(),
            ])->save();

            $payment->transactions()->create([
                'type' => PaymentTransaction::CREATED,
                'to_status' => Payment::PENDING,
                'amount_minor' => $payment->amount_minor,
                'user_id' => $by?->getKey(),
                'reason' => $note,
                'created_at' => now(),
            ]);

            return $this->ledger->reconcile($payment, $note, $by);
        });
    }

    /**
     * The host cancels a stay that had the room.
     *
     * The dates go back on sale through the allocator's lock. Any deposit
     * Rihla holds is Rihla's to refund or keep under the snapshot's terms —
     * that decision is not the host's, so nothing about it happens here.
     *
     * @throws DeskRefusal
     */
    public function cancel(Stay $stay, string $reason): Stay
    {
        if (! $stay->isOccupying()) {
            throw new DeskRefusal($stay->status === Stay::REQUESTED
                ? 'A request is declined, not cancelled.'
                : 'Only a held or confirmed stay can be cancelled.');
        }

        return app(StayAllocator::class)->release($stay, Stay::CANCELLED, $reason);
    }

    /**
     * The room is of the kind booked, in use, and nobody else is in it —
     * now, at check-in; or on any of these nights, when a booking is being
     * put in a room ahead of time ({@see $forTheNights}).
     *
     * @throws DeskRefusal
     */
    public function assertUnitFits(Stay $stay, PropertyUnit $unit, bool $forTheNights = false): void
    {
        if ((int) $unit->room_type_id !== (int) $stay->room_type_id || (int) $unit->property_id !== (int) $stay->property_id) {
            throw new DeskRefusal('That room is not one of the kind this guest booked.');
        }

        if (! $unit->is_active) {
            throw new DeskRefusal($unit->label.' is out of use.');
        }

        $others = Stay::query()
            ->where('unit_id', $unit->getKey())
            ->whereKeyNot($stay->getKey());

        if ($forTheNights) {
            $taken = $others
                ->whereIn('status', [Stay::HELD, Stay::CONFIRMED, Stay::CHECKED_IN])
                ->overlapping($stay->check_in, $stay->check_out)
                ->exists();

            if ($taken) {
                throw new DeskRefusal($unit->label.' is already given to another booking on some of these nights. Choose another room, or leave it unassigned.');
            }

            return;
        }

        if ($others->where('status', Stay::CHECKED_IN)->exists()) {
            throw new DeskRefusal($unit->label.' has somebody in it. Check them out first, or choose another room.');
        }
    }
}
