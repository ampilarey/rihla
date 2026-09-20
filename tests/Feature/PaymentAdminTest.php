<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Payments\Ledger;
use App\Services\Payments\SlipVault;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screens money is handled through.
 *
 * Rendered rather than status-checked: a Filament page answers 200 while a
 * column closure throws in its own Livewire request.
 */
class PaymentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function booking(int $totalMinor = 2_850_000): Booking
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->addDays(60),
                'date_end' => now()->addDays(74),
            ])->getKey(),
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => $totalMinor,
        ]);

        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create([
                'full_name' => 'Hawwa Latheefa',
            ])->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        return $booking->refresh();
    }

    private function slip(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('slip.pdf', 'a transfer slip');
    }

    // ── Who may look ──────────────────────────────────────────────────────

    public function test_finance_can_list_payments(): void
    {
        Payment::factory()->awaitingReview()->create([
            'booking_id' => $this->booking()->getKey(),
            'payer_name' => 'Ibrahim Waheed',
        ]);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(ListPayments::class)
            ->assertOk()
            ->assertSee('Ibrahim Waheed')
            ->assertSee('MVR 28,500');
    }

    public function test_finance_can_reach_the_screen_over_http(): void
    {
        $this->actingAs($this->staff(Access::FINANCE))
            ->get(PaymentResource::getUrl('index'))
            ->assertOk();
    }

    public function test_the_content_manager_cannot(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(PaymentResource::getUrl('index'))
            ->assertForbidden();
    }

    // ── Reconciling is a separate act from recording ─────────────────────

    public function test_booking_staff_are_not_offered_the_reconcile_action(): void
    {
        $payment = Payment::factory()->awaitingReview()->create(['booking_id' => $this->booking()->getKey()]);

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListPayments::class)
            ->assertOk()
            ->assertTableActionHidden('reconcile', $payment)
            ->assertTableActionHidden('refuse', $payment);
    }

    public function test_reconciling_moves_the_bookings_total(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->awaitingReview()->create([
            'booking_id' => $booking->getKey(),
            'amount_minor' => 1_000_000,
        ]);
        $finance = $this->staff(Access::FINANCE);

        Livewire::actingAs($finance)
            ->test(ListPayments::class)
            ->callTableAction('reconcile', $payment, ['note' => 'On the statement']);

        $this->assertSame(Payment::SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(1_000_000, $booking->fresh()->paid_minor);
        $this->assertSame($finance->getKey(), $payment->fresh()->reviewed_by);
    }

    public function test_refusing_needs_a_reason(): void
    {
        $payment = Payment::factory()->awaitingReview()->create(['booking_id' => $this->booking()->getKey()]);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(ListPayments::class)
            ->callTableAction('refuse', $payment, ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(Payment::AWAITING_REVIEW, $payment->fresh()->status);
    }

    // ── Slips ────────────────────────────────────────────────────────────

    /** Seeing a payment exists is not the same as pulling the slip. */
    public function test_the_slip_link_is_hidden_without_the_permission(): void
    {
        $payment = Payment::factory()->awaitingReview()->create(['booking_id' => $this->booking()->getKey()]);
        app(SlipVault::class)->attach($payment, $this->slip());

        Livewire::actingAs($this->staff(Access::PILGRIM_SUPPORT))
            ->test(ListPayments::class)
            ->assertTableActionHidden('slip', $payment);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(ListPayments::class)
            ->assertTableActionVisible('slip', $payment);
    }

    public function test_a_slip_can_be_attached_from_the_screen(): void
    {
        $payment = Payment::factory()->create(['booking_id' => $this->booking()->getKey()]);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(ListPayments::class)
            // A payment with no slip is not yet in the "waiting to be
            // checked" queue, which is the default tab, and an action can
            // only be called on a row the page is drawing.
            ->set('activeTab', 'all')
            ->callTableAction('uploadSlip', $payment, ['slip' => $this->slip()]);

        $payment->refresh();

        $this->assertTrue($payment->hasSlip());
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
    }

    // ── Refunds ──────────────────────────────────────────────────────────

    public function test_a_refund_is_offered_only_on_money_actually_received(): void
    {
        $booking = $this->booking();
        $claimed = Payment::factory()->awaitingReview()->create(['booking_id' => $booking->getKey()]);
        $received = Payment::factory()->succeeded()->create(['booking_id' => $booking->getKey()]);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(ListPayments::class)
            ->set('activeTab', 'all')
            ->assertTableActionHidden('refund', $claimed)
            ->assertTableActionVisible('refund', $received);
    }

    public function test_a_refund_from_the_screen_leaves_the_original_alone(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create(['booking_id' => $booking->getKey(), 'amount_minor' => 1_000_000]);
        app(Ledger::class)->reconcile($payment);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(ListPayments::class)
            ->set('activeTab', 'all')
            ->callTableAction('refund', $payment->fresh(), ['amount' => 3_000, 'reason' => 'Changed departure']);

        $this->assertSame(1_000_000, $payment->fresh()->amount_minor);
        $this->assertSame(Payment::SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(700_000, $booking->fresh()->paid_minor);
    }

    // ── From the booking ─────────────────────────────────────────────────

    public function test_a_payment_can_be_recorded_from_the_booking(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('recordPayment', [
                'method' => Payment::BANK_TRANSFER,
                'amount' => 10_000,
                'payer_name' => 'Ibrahim Waheed',
                'payer_reference' => 'UMRAH RAMADAN',
            ]);

        $payment = Payment::sole();

        $this->assertSame(1_000_000, $payment->amount_minor);
        $this->assertSame('Ibrahim Waheed', $payment->payer_name);
        // Recorded is not received: the booking's total has not moved.
        $this->assertSame(0, $booking->fresh()->paid_minor);
        $this->assertSame(Payment::PENDING, $payment->status);
    }

    public function test_recording_with_a_slip_puts_it_in_the_queue(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('recordPayment', [
                'method' => Payment::BANK_TRANSFER,
                'amount' => 10_000,
                'slip' => $this->slip(),
            ]);

        $this->assertSame(Payment::AWAITING_REVIEW, Payment::sole()->status);
        $this->assertTrue(Payment::sole()->hasSlip());
    }

    /**
     * The permission, not the role.
     *
     * Every role that can open a booking for editing also happens to hold
     * `payment.create` today, so a second role proves nothing — and a role
     * that cannot open the page at all fails on authorisation before it
     * reaches the button, which would look like the guard working while
     * testing something else entirely. This revokes the one permission and
     * leaves everything else in place.
     */
    public function test_the_button_needs_the_permission_and_not_just_the_screen(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertActionVisible('recordPayment');

        $without = $this->staff(Access::OPERATIONS_MANAGER);
        $without->revokePermissionTo('payment.create');
        // Direct revoke does not beat the role grant in spatie, so the role
        // goes too and the permissions it still needs are given back.
        $without->removeRole(Access::OPERATIONS_MANAGER);
        $without->givePermissionTo(['admin.access', 'booking.viewAny', 'booking.view', 'booking.update']);

        Livewire::actingAs($without->fresh())
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertActionHidden('recordPayment');
    }

    /**
     * The two figures disagreeing silently is the failure this guards: a
     * customer has sent a slip, the booking says MVR 0 paid, and nobody on
     * the phone can see that anything is waiting.
     */
    public function test_the_booking_says_what_is_claimed_but_not_checked(): void
    {
        $booking = $this->booking();
        Payment::factory()->awaitingReview()->create([
            'booking_id' => $booking->getKey(),
            'amount_minor' => 1_000_000,
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertOk()
            ->assertSee('MVR 10,000 is claimed but not checked yet');
    }

    public function test_the_booking_says_nothing_special_when_nothing_is_waiting(): void
    {
        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(EditBooking::class, ['record' => $this->booking()->getKey()])
            ->assertOk()
            ->assertDontSee('claimed but not checked')
            ->assertSee('Nothing is typed into this figure');
    }
}
