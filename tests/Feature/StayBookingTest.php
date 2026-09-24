<?php

namespace Tests\Feature;

use App\Exceptions\RoomNotAvailable;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Stays\StayBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Request → held → deposit → confirmed — §15.4 (Phase 9.3).
 *
 * The flow, on top of the allocator `StayAllocationTest` already covers and
 * the arithmetic `StayAvailabilityTest` already covers. What is new here is
 * the money and the promise: what the customer was quoted, what they were
 * told the terms were, and the fact that neither may move afterwards.
 */
class StayBookingTest extends TestCase
{
    use RefreshDatabase;

    private StayBooking $booking;

    private RoomType $room;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->booking = app(StayBooking::class);
        $this->customer = Customer::factory()->create();

        $property = Property::factory()->create([
            'currency' => 'USD',
            'min_nights' => 1,
            'deposit_pct' => 30,
            'balance_days_before' => 14,
            'free_cancel_days' => 14,
        ]);

        $this->room = RoomType::factory()->create([
            'property_id' => $property->id,
            'quantity' => 1,
            'base_rate_minor' => 10000,
        ]);
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function request(string $checkIn = '2027-03-03', string $checkOut = '2027-03-05'): Stay
    {
        return $this->booking->request(
            $this->customer,
            $this->room->fresh(),
            $this->date($checkIn),
            $this->date($checkOut),
            adults: 2,
        );
    }

    // ── Asking ───────────────────────────────────────────────────────────

    public function test_a_request_records_the_price_and_the_deposit(): void
    {
        $stay = $this->request();

        $this->assertSame(Stay::REQUESTED, $stay->status);
        $this->assertSame(2, $stay->nights);
        $this->assertSame(20000, $stay->total_minor);
        $this->assertSame(6000, $stay->deposit_minor);
        $this->assertSame('USD', $stay->currency);
        $this->assertNotNull($stay->requested_at);
    }

    /**
     * §15.2 decision 1 again, at the level above the allocator: two people
     * may ask for the same last room, and the partner decides. Refusing the
     * second ask would be Rihla promising availability nobody gave it.
     */
    public function test_two_people_may_ask_for_the_same_last_room(): void
    {
        $this->request();
        $second = $this->request();

        $this->assertSame(Stay::REQUESTED, $second->status);
        $this->assertSame(2, Stay::count());
    }

    /** But not once somebody actually has it. */
    public function test_asking_for_dates_already_held_is_refused(): void
    {
        $this->booking->confirmWithPartner($this->request());

        $this->expectException(RoomNotAvailable::class);

        $this->request();
    }

    // ── The promise, frozen ──────────────────────────────────────────────

    /**
     * The price is taken when the customer asks, not when the partner
     * answers. A season edited in between must not move it — they agreed to
     * a number, and re-deriving it later charges them something they never
     * saw.
     */
    public function test_a_rate_change_after_the_request_does_not_move_the_price(): void
    {
        $stay = $this->request();

        Rate::factory()->create([
            'room_type_id' => $this->room->id,
            'starts_on' => '2027-03-01',
            'ends_on' => '2027-03-31',
            'rate_minor' => 99000,
        ]);

        $this->booking->confirmWithPartner($stay);

        $this->assertSame(20000, $stay->fresh()->total_minor);
        $this->assertSame(6000, $stay->fresh()->deposit_minor);
    }

    /**
     * And the terms with it.
     *
     * The property's policy may be edited afterwards; the customer is held
     * to what the page said on the day. This is the same shape of defect as
     * the migration that pointed at a constant and re-coloured live rows
     * when the constant moved — recorded in `AGENTS.md`.
     */
    public function test_the_policy_is_frozen_onto_the_stay(): void
    {
        $stay = $this->request();

        $this->assertSame(30, $stay->rate_snapshot['policy']['deposit_pct']);
        $this->assertSame(14, $stay->rate_snapshot['policy']['free_cancel_days']);

        $this->room->property->update([
            'deposit_pct' => 80,
            'free_cancel_days' => 0,
        ]);

        $this->assertSame(30, $stay->fresh()->rate_snapshot['policy']['deposit_pct']);
        $this->assertSame(14, $stay->fresh()->rate_snapshot['policy']['free_cancel_days']);
    }

    public function test_the_nightly_breakdown_is_kept_not_just_the_total(): void
    {
        $stay = $this->request();

        $this->assertSame(
            ['2027-03-03' => 10000, '2027-03-04' => 10000],
            $stay->rate_snapshot['nightly'],
        );
    }

    // ── The partner's answer ─────────────────────────────────────────────

    public function test_confirming_with_the_partner_takes_the_dates_and_sets_a_deposit_deadline(): void
    {
        $stay = $this->booking->confirmWithPartner($this->request());

        $this->assertSame(Stay::HELD, $stay->status);
        $this->assertNotNull($stay->deposit_due_at);
        $this->assertTrue($stay->deposit_due_at->equalTo($stay->expires_at));
    }

    public function test_declining_records_the_reason_the_customer_sees(): void
    {
        $stay = $this->booking->decline($this->request(), 'The partner is full that week.');

        $this->assertSame(Stay::DECLINED, $stay->fresh()->status);
        $this->assertSame('The partner is full that week.', $stay->fresh()->cancellation_reason);
    }

    // ── The deposit ──────────────────────────────────────────────────────

    /**
     * A payment against a **stay**, which is what Phase 8.6 made possible.
     * Before it, money could only belong to a booking.
     */
    public function test_the_deposit_is_a_payment_against_the_stay(): void
    {
        $stay = $this->booking->confirmWithPartner($this->request());

        $payment = $this->booking->requestDeposit($stay, 'bank_transfer');

        $this->assertSame(Stay::class, $payment->payable_type);
        $this->assertSame($stay->getKey(), $payment->payable_id);
        $this->assertSame(6000, $payment->amount_minor);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame(Payment::PENDING, $payment->status);
    }

    public function test_settling_the_deposit_confirms_the_stay(): void
    {
        $stay = $this->booking->confirmWithPartner($this->request());
        $payment = $this->booking->requestDeposit($stay, 'bank_transfer');

        $confirmed = $this->booking->settle($payment);

        $this->assertSame(Stay::CONFIRMED, $confirmed->status);
        $this->assertSame(6000, $confirmed->paid_minor);
        $this->assertNull($confirmed->expires_at);
    }

    /**
     * Money arriving is not the same as the deposit being met.
     *
     * A part payment leaves the stay held, the clock still running and the
     * rest still owed — confirming on it would take the dates off the
     * calendar for somebody who has not paid the agreed amount.
     */
    public function test_a_part_payment_does_not_confirm_the_stay(): void
    {
        $stay = $this->booking->confirmWithPartner($this->request());

        $payment = $this->booking->requestDeposit($stay, 'bank_transfer');
        $payment->forceFill(['amount_minor' => 2000])->save();

        $still = $this->booking->settle($payment);

        $this->assertSame(Stay::HELD, $still->status);
        $this->assertSame(2000, $still->paid_minor);
        $this->assertNotNull($still->expires_at);
    }

    /** The cached total is the Ledger's, and it is a sum rather than a counter. */
    public function test_the_paid_total_is_re_derived_from_succeeded_payments(): void
    {
        $stay = $this->booking->confirmWithPartner($this->request());

        $first = $this->booking->requestDeposit($stay, 'bank_transfer');
        $first->forceFill(['amount_minor' => 2000])->save();
        $this->booking->settle($first);

        $second = $this->booking->requestDeposit($stay->fresh(), 'bank_transfer');
        $second->forceFill(['amount_minor' => 4000])->save();
        $confirmed = $this->booking->settle($second);

        $this->assertSame(6000, $confirmed->paid_minor);
        $this->assertSame(Stay::CONFIRMED, $confirmed->status);
    }

    public function test_what_is_still_owed_after_the_deposit(): void
    {
        $stay = $this->booking->confirmWithPartner($this->request());
        $confirmed = $this->booking->settle($this->booking->requestDeposit($stay, 'bank_transfer'));

        $this->assertSame(14000, $confirmed->outstanding()->minor);
    }

    /**
     * The deposit arrives after the hold lapsed and the dates have gone.
     *
     * It throws, which is the truth of the situation and far better found
     * here — where the money can be refunded — than at a guesthouse door.
     */
    public function test_a_deposit_that_arrives_too_late_is_refused(): void
    {
        $stay = $this->booking->confirmWithPartner($this->request());
        $payment = $this->booking->requestDeposit($stay, 'bank_transfer');

        $stay->forceFill(['expires_at' => now()->subHour()])->save();

        // Somebody else takes the dates, which expires the lapsed hold.
        $this->booking->confirmWithPartner($this->request());

        $this->expectException(RoomNotAvailable::class);

        $this->booking->settle($payment);
    }

    // ── When the rest falls due ──────────────────────────────────────────

    public function test_the_balance_falls_due_the_agreed_number_of_days_before(): void
    {
        $stay = $this->request();

        $this->assertSame(
            '2027-02-17',
            $this->booking->balanceDueAt($stay)->toDateString(),
        );
    }

    /**
     * Somebody booking four days out does not get a due date in the past,
     * which is what subtracting fourteen days blindly would give them.
     */
    public function test_a_late_booking_owes_the_balance_now_rather_than_in_the_past(): void
    {
        $checkIn = CarbonImmutable::now()->addDays(4);

        $stay = $this->request(
            $checkIn->toDateString(),
            $checkIn->addDays(2)->toDateString(),
        );

        $before = CarbonImmutable::now();
        $due = $this->booking->balanceDueAt($stay);

        // "Now", not a date fourteen days before a check-in four days away.
        // Asserted as a window rather than `isPast()`, because the clock
        // moves between computing it and reading it and a due date of
        // exactly now is a microsecond in the past by the time it is asked.
        $this->assertTrue($due->greaterThanOrEqualTo($before));
        $this->assertTrue($due->lessThanOrEqualTo($before->addMinute()));
    }

    // ── Cancelling ───────────────────────────────────────────────────────

    public function test_cancellation_is_free_well_before_check_in(): void
    {
        $stay = $this->request();

        $this->assertTrue($this->booking->cancellationIsFree($stay, $this->date('2027-02-01')));
    }

    public function test_cancellation_is_not_free_inside_the_window(): void
    {
        $stay = $this->request();

        $this->assertFalse($this->booking->cancellationIsFree($stay, $this->date('2027-02-25')));
    }

    /**
     * Read from the snapshot, not the property.
     *
     * Editing the guesthouse's terms must not retroactively move a
     * cancellation deadline somebody already agreed to — in either
     * direction.
     */
    public function test_editing_the_property_does_not_move_an_agreed_deadline(): void
    {
        $stay = $this->request();

        $this->room->property->update(['free_cancel_days' => 90]);

        $this->assertTrue(
            $this->booking->cancellationIsFree($stay->fresh(), $this->date('2027-02-01')),
        );
    }
}
