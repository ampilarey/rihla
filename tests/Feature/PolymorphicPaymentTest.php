<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\Ledger;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * A payment belongs to whatever it is against — §15.3 (Phase 8.6).
 *
 * Phase 9 sells a stay, which has no departure, no seats and no
 * travellers, and its money still has to land in the same ledger. This
 * covers the seam that makes that possible, and the guard that stops it
 * being half-used before the rest of it exists.
 */
class PolymorphicPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): Booking
    {
        return Booking::factory()->create();
    }

    public function test_the_old_column_is_gone(): void
    {
        // Two sources of truth for the same fact is the defect this phase
        // exists to avoid, so the column does not linger beside the pair
        // that replaced it.
        $this->assertFalse(Schema::hasColumn('payments', 'booking_id'));
        $this->assertTrue(Schema::hasColumn('payments', 'payable_type'));
        $this->assertTrue(Schema::hasColumn('payments', 'payable_id'));
    }

    public function test_a_payment_resolves_the_booking_it_is_against(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
        ]);

        $this->assertTrue($payment->payable->is($booking));
        $this->assertTrue($payment->booking()->is($booking));
        $this->assertSame($booking->getKey(), $payment->bookingKey());
    }

    public function test_a_booking_finds_its_money_through_the_morph(): void
    {
        $booking = $this->booking();
        $mine = Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
        ]);
        Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $this->booking()->getKey(),
        ]);

        $this->assertSame([$mine->getKey()], $booking->payments->pluck('id')->all());
    }

    /**
     * The whole point of the seam: money against something that is not a
     * booking is storable today, well before a stay exists to be one.
     */
    public function test_money_can_be_recorded_against_something_that_is_not_a_booking(): void
    {
        $payment = Payment::factory()->create([
            'payable_type' => 'App\Models\SomethingPhaseNineWillBuild',
            'payable_id' => 1,
        ]);

        $this->assertNull($payment->bookingKey());
        $this->assertNull($payment->booking());
    }

    /**
     * Proved by planting the state Phase 9 will create: the ledger keeps
     * `bookings.paid_minor` and nothing else, so it must refuse money it
     * has nowhere to put rather than accept it and quietly lose the total.
     */
    public function test_the_ledger_refuses_money_it_has_no_total_for(): void
    {
        $payment = Payment::factory()->create([
            'payable_type' => 'App\Models\SomethingPhaseNineWillBuild',
            'payable_id' => 1,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/SomethingPhaseNineWillBuild/');

        app(Ledger::class)->reconcile($payment);
    }

    /** A refund lands against whatever the payment it reverses was against. */
    public function test_a_refund_inherits_the_payable(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'amount_minor' => 1_000_000,
        ]);

        $ledger = app(Ledger::class);
        $ledger->reconcile($payment);
        $refund = $ledger->refund($payment, Money::ofMinor(400_000, 'MVR'));

        $this->assertSame(Booking::class, $refund->payable_type);
        $this->assertSame($booking->getKey(), $refund->payable_id);
        $this->assertSame(600_000, $booking->refresh()->paid_minor);
    }
}
