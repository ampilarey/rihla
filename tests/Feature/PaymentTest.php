<?php

namespace Tests\Feature;

use App\Exceptions\GatewayNotConfigured;
use App\Exceptions\IllegalPaymentTransition;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\Drivers\BankTransfer;
use App\Services\Payments\Drivers\BmlConnect;
use App\Services\Payments\Gateways;
use App\Services\Payments\Ledger;
use App\Services\Payments\SlipVault;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Money received against a booking — §5.3.
 *
 * The thing this whole design protects: a paid total that is a re-derivation
 * of what was actually received, never an increment, and never written by
 * anything except the ledger.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    private function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    private function booking(int $totalMinor = 2_850_000): Booking
    {
        $departure = Departure::factory()->withSeats(10)->create([
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(74),
        ]);

        return Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => $totalMinor,
        ]);
    }

    private function slip(string $contents = 'a transfer slip'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('slip.pdf', $contents);
    }

    // ── Recording money ──────────────────────────────────────────────────

    public function test_a_bank_transfer_starts_as_a_claim_nobody_has_checked(): void
    {
        $booking = $this->booking();

        $payment = app(BankTransfer::class)->start($booking, Money::ofMajor(28_500), [
            'payer_name' => 'Ibrahim Waheed',
            'payer_reference' => 'UMRAH RAMADAN',
        ]);

        $this->assertSame(Payment::PENDING, $payment->status);
        $this->assertSame(2_850_000, $payment->amount_minor);
        $this->assertSame('Ibrahim Waheed', $payment->payer_name);
        // Nothing is on the booking until somebody says the money is in.
        $this->assertSame(0, $booking->fresh()->paid_minor);
    }

    /** A reference nobody can quote is a payment nobody can find. */
    public function test_a_payment_gets_a_reference(): void
    {
        $payment = Payment::factory()->create();

        $this->assertMatchesRegularExpression('/^RIH-P-\d{4}-\d{4}$/', (string) $payment->fresh()->reference);
    }

    public function test_reconciling_moves_the_bookings_paid_total(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'amount_minor' => 1_000_000,
        ]);

        $this->ledger()->reconcile($payment, 'Seen on the statement');

        $this->assertSame(Payment::SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(1_000_000, $booking->fresh()->paid_minor);
        $this->assertSame('MVR 18,500', $booking->fresh()->balance()->format());
    }

    /**
     * The hazard this class exists for: a total that is re-derived cannot
     * drift, and a total that is incremented can.
     */
    public function test_the_paid_total_is_a_sum_and_not_a_running_increment(): void
    {
        $booking = $this->booking();

        foreach ([1_000_000, 500_000, 1_350_000] as $amount) {
            $this->ledger()->reconcile(
                Payment::factory()->create(['payable_type' => Booking::class, 'payable_id' => $booking->getKey(), 'amount_minor' => $amount]),
            );
        }

        $this->assertSame(2_850_000, $booking->fresh()->paid_minor);

        // Somebody fixes a row by hand — an import, a console session. The
        // recomputation puts the cached total right without a reconciliation
        // that did not happen.
        Payment::where('payable_type', Booking::class)->where('payable_id', $booking->getKey())
            ->orderByDesc('id')->first()
            ->forceFill(['amount_minor' => 1_000_000])->save();

        $this->ledger()->recompute($booking);

        $this->assertSame(2_500_000, $booking->fresh()->paid_minor);
    }

    public function test_a_refused_payment_adds_nothing(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->awaitingReview()->create(['payable_type' => Booking::class, 'payable_id' => $booking->getKey()]);

        $this->ledger()->refuse($payment, 'The slip is for MVR 2,850, not MVR 28,500');

        $this->assertSame(Payment::FAILED, $payment->fresh()->status);
        $this->assertSame('The slip is for MVR 2,850, not MVR 28,500', $payment->fresh()->rejection_reason);
        $this->assertSame(0, $booking->fresh()->paid_minor);
    }

    // ── Refunds ──────────────────────────────────────────────────────────

    /**
     * A refund is a new negative row, not a status change, so the original
     * keeps its date, its reference and its slip — what was actually
     * received.
     */
    public function test_a_refund_is_a_negative_payment_that_leaves_the_original_alone(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create(['payable_type' => Booking::class, 'payable_id' => $booking->getKey(), 'amount_minor' => 1_000_000]);

        $this->ledger()->reconcile($payment);
        $refund = $this->ledger()->refund($payment, Money::ofMajor(3_000), 'Changed departure');

        $this->assertSame(-300_000, $refund->amount_minor);
        $this->assertTrue($refund->isRefund());
        $this->assertSame($payment->getKey(), $refund->refund_of_id);

        // The original is untouched.
        $this->assertSame(Payment::SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(1_000_000, $payment->fresh()->amount_minor);

        $this->assertSame(700_000, $booking->fresh()->paid_minor);
    }

    public function test_a_refund_of_the_whole_amount_needs_no_figure(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create(['payable_type' => Booking::class, 'payable_id' => $booking->getKey(), 'amount_minor' => 1_000_000]);

        $this->ledger()->reconcile($payment);
        $this->ledger()->refund($payment, null, 'Cancelled');

        $this->assertSame(0, $booking->fresh()->paid_minor);
    }

    /** Whichever sign the person typing it used. */
    public function test_a_refund_is_negative_however_it_was_asked_for(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create(['payable_type' => Booking::class, 'payable_id' => $booking->getKey(), 'amount_minor' => 1_000_000]);
        $this->ledger()->reconcile($payment);

        $refund = $this->ledger()->refund($payment, Money::ofMinor(-250_000));

        $this->assertSame(-250_000, $refund->amount_minor);
    }

    // ── The state machine ────────────────────────────────────────────────

    /**
     * Succeeded is final. Money that turns out not to have arrived is
     * reversed by a refund, never un-succeeded: that would erase the record
     * of a decision somebody made.
     */
    public function test_a_succeeded_payment_cannot_be_walked_back(): void
    {
        $payment = Payment::factory()->create();
        $this->ledger()->reconcile($payment);

        $this->expectException(IllegalPaymentTransition::class);

        $payment->fresh()->transitionTo(Payment::FAILED, 'Actually it bounced');
    }

    public function test_every_move_records_who_and_why(): void
    {
        $staff = User::factory()->create()->assignRole(Access::FINANCE);
        $payment = Payment::factory()->awaitingReview()->create();

        $this->ledger()->reconcile($payment, 'Matched against the BML statement', $staff);

        $event = $payment->fresh()->transactions()->where('type', PaymentTransaction::REVIEWED)->sole();

        $this->assertSame(Payment::AWAITING_REVIEW, $event->from_status);
        $this->assertSame(Payment::SUCCEEDED, $event->to_status);
        $this->assertSame($staff->getKey(), $event->user_id);
        $this->assertSame('Matched against the BML statement', $event->reason);
        $this->assertSame($staff->getKey(), $payment->fresh()->reviewed_by);
    }

    // ── Idempotency, which is the database's job ─────────────────────────

    /**
     * The plan's §5.3: "callback idempotency rests on a unique constraint
     * over the provider event ID". Tested against the constraint rather
     * than against a driver, because the driver that will use it does not
     * exist yet — and the guarantee is the index, not the code above it.
     */
    public function test_the_same_gateway_event_cannot_be_recorded_twice(): void
    {
        $payment = Payment::factory()->create();

        $event = [
            'payment_id' => $payment->getKey(),
            'provider' => 'bml',
            'provider_event_id' => 'evt_0f9a2c',
            'type' => PaymentTransaction::CALLBACK,
            'created_at' => now(),
        ];

        PaymentTransaction::create($event);

        $this->expectException(QueryException::class);
        // Named, so the test cannot pass on some other database error — a
        // NOT NULL or a foreign key would satisfy a bare QueryException and
        // prove nothing about idempotency.
        $this->expectExceptionMessageMatches('/UNIQUE|Duplicate entry|Integrity constraint violation: 1062/i');

        PaymentTransaction::create($event);
    }

    /** Internal events carry no provider, and there are many of them. */
    public function test_events_without_a_provider_do_not_collide(): void
    {
        $payment = Payment::factory()->create();

        foreach ([1, 2, 3] as $_) {
            PaymentTransaction::create([
                'payment_id' => $payment->getKey(),
                'type' => PaymentTransaction::CREATED,
                'created_at' => now(),
            ]);
        }

        $this->assertSame(3, $payment->transactions()->count());
    }

    // ── Slips ────────────────────────────────────────────────────────────

    public function test_a_slip_puts_the_payment_in_front_of_somebody(): void
    {
        $payment = Payment::factory()->create();

        app(SlipVault::class)->attach($payment, $this->slip());

        $payment->refresh();

        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
        $this->assertTrue($payment->hasSlip());
        $this->assertSame(hash('sha256', 'a transfer slip'), $payment->slip_checksum);
        $this->assertTrue(Storage::disk('documents')->exists($payment->slip_path));
    }

    /** The customer who sends the same image three times on WhatsApp. */
    public function test_the_same_file_again_changes_nothing(): void
    {
        $payment = Payment::factory()->create();
        $vault = app(SlipVault::class);

        $vault->attach($payment, $this->slip());
        $first = $payment->fresh()->slip_path;

        $vault->attach($payment->fresh(), $this->slip());

        $this->assertSame($first, $payment->fresh()->slip_path);
        $this->assertSame(1, $payment->fresh()->transactions()->where('type', PaymentTransaction::SLIP_UPLOADED)->count());
    }

    /** A replaced slip is superseded, never overwritten — [R-8]'s rule. */
    public function test_a_better_copy_keeps_the_first_one(): void
    {
        $payment = Payment::factory()->create();
        $vault = app(SlipVault::class);

        $vault->attach($payment, $this->slip('the blurry one'));
        $first = $payment->fresh()->slip_path;

        $vault->attach($payment->fresh(), $this->slip('the readable one'));

        $payment->refresh();

        $this->assertNotSame($first, $payment->slip_path);
        $this->assertTrue(Storage::disk('documents')->exists($first), 'The superseded file is kept.');

        $replaced = $payment->transactions()->where('type', PaymentTransaction::SLIP_REPLACED)->sole();

        $this->assertSame($first, $replaced->payload['replaced_path']);
        $this->assertSame(hash('sha256', 'the blurry one'), $replaced->payload['replaced_checksum']);
    }

    /** The filename on disk says nothing about whose money this is. */
    public function test_the_stored_filename_is_random(): void
    {
        $payment = Payment::factory()->create();

        app(SlipVault::class)->attach($payment, $this->slip());

        $this->assertStringNotContainsString('slip.pdf', (string) $payment->fresh()->slip_path);
        $this->assertSame('slip.pdf', $payment->fresh()->slip_original_filename);
    }

    // ── The only door to a slip ──────────────────────────────────────────

    private function withSlip(): Payment
    {
        $payment = Payment::factory()->create();
        app(SlipVault::class)->attach($payment, $this->slip());

        return $payment->fresh();
    }

    public function test_an_unsigned_slip_url_is_refused(): void
    {
        $payment = $this->withSlip();

        $this->actingAs(User::factory()->create()->assignRole(Access::FINANCE))
            ->get(route('payments.slip', ['payment' => $payment->getKey()]))
            ->assertForbidden();
    }

    public function test_a_signed_url_without_the_permission_is_refused(): void
    {
        $payment = $this->withSlip();

        $this->actingAs(User::factory()->create()->assignRole(Access::PILGRIM_SUPPORT))
            ->get(app(SlipVault::class)->downloadUrl($payment))
            ->assertForbidden();
    }

    public function test_finance_can_pull_the_slip(): void
    {
        $payment = $this->withSlip();

        $this->actingAs(User::factory()->create()->assignRole(Access::FINANCE))
            ->get(app(SlipVault::class)->downloadUrl($payment))
            ->assertOk()
            ->assertDownload('slip.pdf');
    }

    /**
     * Every *download* is audited, not just every change.
     *
     * "Who has had a copy of this?" is the question asked after something
     * goes wrong, and a trail that records only uploads cannot answer it.
     */
    public function test_pulling_a_slip_is_written_to_the_audit_trail(): void
    {
        $payment = $this->withSlip();
        $finance = User::factory()->create()->assignRole(Access::FINANCE);

        $this->actingAs($finance)->get(app(SlipVault::class)->downloadUrl($payment))->assertOk();

        $log = AuditLog::where('event', AuditLog::DOWNLOADED)
            ->where('auditable_id', $payment->getKey())
            ->sole();

        $this->assertSame($finance->getKey(), $log->user_id);
        $this->assertSame($payment->slip_checksum, $log->new_values['checksum']);
        // The file itself is not in the trail, and neither is anything that
        // would let somebody reconstruct it.
        $this->assertArrayNotHasKey('contents', $log->new_values);
    }

    /** A link that expires is the point of a link that expires. */
    public function test_an_expired_slip_link_is_refused(): void
    {
        $payment = $this->withSlip();
        $url = app(SlipVault::class)->downloadUrl($payment);

        $this->travel((int) config('payments.slips.link_minutes', 5) + 1)->minutes();

        $this->actingAs(User::factory()->create()->assignRole(Access::FINANCE))
            ->get($url)
            ->assertForbidden();
    }

    // ── The gateways ─────────────────────────────────────────────────────

    /**
     * Card is off and says so, rather than offering a button that throws.
     */
    public function test_card_is_not_offered_while_bml_is_not_connected(): void
    {
        $this->assertFalse(app(BmlConnect::class)->isAvailable());
        $this->assertArrayNotHasKey('card', app(Gateways::class)->available());
        $this->assertArrayHasKey('bank_transfer', app(Gateways::class)->available());
    }

    /** A half-configured gateway is not a configured one. */
    public function test_card_stays_off_with_the_switch_on_but_no_credentials(): void
    {
        config(['payments.methods.card.enabled' => true]);

        $this->assertFalse(app(BmlConnect::class)->isAvailable());
    }

    public function test_the_bml_driver_refuses_loudly_rather_than_pretending(): void
    {
        $this->expectException(GatewayNotConfigured::class);
        $this->expectExceptionMessage('Nothing has been charged.');

        app(BmlConnect::class)->start($this->booking(), Money::ofMajor(28_500));
    }

    /**
     * **No invented account number.** config/payments.php ships empty, and
     * while it is empty there is nothing to tell a customer. A made-up
     * account is not a placeholder — it is an instruction to send money
     * somewhere.
     */
    public function test_no_bank_account_is_invented_when_none_is_configured(): void
    {
        $instructions = app(BankTransfer::class)->instructions(Money::ofMajor(28_500));

        $this->assertNull($instructions['account']);
        $this->assertNull($instructions['bank']);
        $this->assertFalse(app(BankTransfer::class)->hasAccountFor('MVR'));
    }

    public function test_a_configured_account_is_shown(): void
    {
        config([
            'payments.bank.name' => 'Bank of Maldives',
            'payments.bank.accounts' => ['MVR' => '7730000123456'],
        ]);

        $instructions = app(BankTransfer::class)->instructions(Money::ofMajor(28_500));

        $this->assertSame('7730000123456', $instructions['account']);
        $this->assertSame('MVR 28,500', $instructions['amount']);
        $this->assertTrue(app(BankTransfer::class)->hasAccountFor('mvr'));
    }

    public function test_an_unknown_method_is_refused_rather_than_guessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(Gateways::class)->for('bitcoin');
    }

    // ── Who may do what ──────────────────────────────────────────────────

    /** Recording a claim and deciding the money is in are different acts. */
    public function test_booking_staff_can_record_a_payment_but_not_reconcile_it(): void
    {
        $staff = User::factory()->create()->assignRole(Access::BOOKING_STAFF);

        $this->assertTrue($staff->can('payment.create'));
        $this->assertFalse($staff->can('payment.reconcile'));
    }

    public function test_finance_reconciles(): void
    {
        $staff = User::factory()->create()->assignRole(Access::FINANCE);

        $this->assertTrue($staff->can('payment.reconcile'));
        $this->assertTrue($staff->can('payment.refund'));
        $this->assertTrue($staff->can('payment.download'));
    }

    /** Can say "yes, we have your transfer" without pulling the slip. */
    public function test_pilgrim_support_sees_that_a_payment_exists_and_no_more(): void
    {
        $staff = User::factory()->create()->assignRole(Access::PILGRIM_SUPPORT);

        $this->assertTrue($staff->can('payment.view'));
        $this->assertFalse($staff->can('payment.download'));
        $this->assertFalse($staff->can('payment.reconcile'));
    }

    /** A record of money received that somebody can remove is not a record. */
    public function test_nobody_may_delete_a_payment(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $this->assertFalse(
                User::factory()->create()->assignRole($role)->can('payment.delete'),
                "[{$role}] may delete payments.",
            );
        }
    }

    /** The Content Manager edits the website; money is not the website. */
    public function test_the_content_manager_holds_nothing_here(): void
    {
        $staff = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->assertFalse($staff->can('payment.viewAny'));
        $this->assertFalse($staff->can('payment.view'));
    }
}
