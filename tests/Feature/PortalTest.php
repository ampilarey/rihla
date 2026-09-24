<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PortalAccess;
use App\Models\Traveller;
use App\Models\User;
use App\Models\VisaApplication;
use App\Services\Portal\Gatekeeper;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Pilgrim Portal — §6.1.
 *
 * Access is a link staff issue and send themselves, because there is no
 * SMTP and no SMS gateway: a password login would have no password reset,
 * and a login somebody can be locked out of for ever is worse than none.
 * The link is therefore a bearer credential, and most of what is tested
 * here is that it behaves like one.
 */
class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    private function gate(): Gatekeeper
    {
        return app(Gatekeeper::class);
    }

    private function booking(int $travellers = 1): Booking
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->addDays(60),
                'date_end' => now()->addDays(74),
                'airline' => 'Emirates',
            ])->getKey(),
            'seats' => $travellers,
            'currency' => 'MVR',
            'total_minor' => 2_850_000,
        ]);

        for ($i = 0; $i < $travellers; $i++) {
            $booking->travellers()->create([
                'traveller_id' => Traveller::factory()->for($customer)->create([
                    'full_name' => "Traveller {$i}",
                ])->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $i === 0,
            ]);
        }

        return $booking->refresh();
    }

    /** Walk in the front door, the way a customer does. */
    private function enter(Booking $booking): string
    {
        $token = $this->gate()->issue($booking);

        $this->get("/en/portal/enter/{$token}")->assertRedirect('/en/portal');

        return $token;
    }

    // ── The door ─────────────────────────────────────────────────────────

    public function test_a_live_link_opens_the_booking(): void
    {
        $booking = $this->booking();
        $this->enter($booking);

        $this->get('/en/portal')
            ->assertOk()
            ->assertSee($booking->reference)
            ->assertSee('Emirates');
    }

    /**
     * The token is spent once and swapped for a session.
     *
     * Left in the address bar it would sit in every screenshot, in the
     * history of a shared phone, and in the Referer header of every outbound
     * click from the portal.
     */
    public function test_the_token_does_not_stay_in_the_address_bar(): void
    {
        $token = $this->gate()->issue($this->booking());

        $this->get("/en/portal/enter/{$token}")
            ->assertRedirect('/en/portal');
    }

    public function test_without_a_session_the_portal_sends_you_to_the_door(): void
    {
        $this->get('/en/portal')->assertRedirect('/en/portal/locked');
        $this->get('/en/portal/documents')->assertRedirect('/en/portal/locked');
    }

    /** A wrong link and a cancelled one are different problems. */
    public function test_a_cancelled_link_says_it_was_cancelled(): void
    {
        $booking = $this->booking();
        $token = $this->gate()->issue($booking);

        $this->gate()->revokeAllFor($booking);

        $this->followingRedirects()
            ->get("/en/portal/enter/{$token}")
            ->assertOk()
            ->assertSee('has been cancelled');
    }

    public function test_an_expired_link_says_it_expired(): void
    {
        $booking = $this->booking();
        $token = $this->gate()->issue($booking);

        $this->travel((int) config('portal.link_days') + 1)->days();

        $this->followingRedirects()
            ->get("/en/portal/enter/{$token}")
            ->assertOk()
            ->assertSee('has expired');
    }

    public function test_a_token_nobody_issued_is_refused(): void
    {
        $this->followingRedirects()
            ->get('/en/portal/enter/'.str_repeat('a', 40))
            ->assertOk()
            ->assertSee('not one of ours');
    }

    /**
     * The plaintext exists once and is never stored.
     *
     * A leaked database backup must not be a set of working keys to
     * everybody's booking.
     */
    public function test_the_token_is_stored_hashed_and_never_in_the_clear(): void
    {
        $token = $this->gate()->issue($this->booking());
        $row = PortalAccess::sole();

        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode($row->getAttributes()));
    }

    public function test_using_a_link_is_stamped(): void
    {
        $booking = $this->booking();
        $this->enter($booking);

        $row = PortalAccess::sole();

        $this->assertSame(1, $row->uses);
        $this->assertNotNull($row->last_used_at);
        $this->assertNotNull($row->first_used_ip);
    }

    /** Twelve hours, not the site's own session lifetime of weeks. */
    public function test_a_portal_session_expires_on_its_own(): void
    {
        $this->enter($this->booking());

        $this->get('/en/portal')->assertOk();

        $this->travel((int) config('portal.session_hours') + 1)->hours();

        $this->get('/en/portal')->assertRedirect('/en/portal/locked');
    }

    public function test_signing_out_ends_it(): void
    {
        $this->enter($this->booking());

        $this->post('/en/portal/leave')->assertRedirect('/en');

        $this->get('/en/portal')->assertRedirect('/en/portal/locked');
    }

    /**
     * No portal URL carries an identifier, so there is nothing to tamper
     * with: the session says which booking, and a visitor who edits the
     * address bar sees their own or nothing.
     */
    public function test_a_session_for_one_booking_shows_only_that_booking(): void
    {
        $mine = $this->booking();
        $theirs = $this->booking();

        $this->enter($mine);

        $this->get('/en/portal')
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->fresh()->reference);
    }

    // ── What it shows ────────────────────────────────────────────────────

    public function test_it_names_what_each_traveller_still_needs(): void
    {
        $booking = $this->booking(2);
        $this->enter($booking);

        $this->get('/en/portal')
            ->assertOk()
            ->assertSee('Traveller 0')
            ->assertSee('Still needed:')
            ->assertSee('your passport')
            ->assertSee('your Umrah permit')
            // The state the whole design exists to express.
            ->assertSee('two separate approvals');
    }

    public function test_money_waiting_to_be_checked_is_named_rather_than_hidden(): void
    {
        $booking = $this->booking();
        Payment::factory()->awaitingReview()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'amount_minor' => 1_000_000,
        ]);

        $this->enter($booking);

        $this->get('/en/portal')
            ->assertOk()
            ->assertSee('MVR 10,000 waiting to be checked')
            // A pilgrim's words, not the admin screen's "awaiting review".
            ->assertSee('We are checking it');
    }

    /** **No invented account number**, here as on the confirmation page. */
    public function test_it_invents_no_bank_details(): void
    {
        $this->enter($this->booking());

        $this->get('/en/portal')
            ->assertOk()
            ->assertDontSee('Account number');
    }

    public function test_it_shows_the_account_once_one_exists(): void
    {
        config([
            'payments.bank.name' => 'Bank of Maldives',
            'payments.bank.accounts' => ['MVR' => '7730000123456'],
        ]);

        $this->enter($this->booking());

        $this->get('/en/portal')->assertOk()->assertSee('7730000123456');
    }

    /**
     * A section with no records behind it does not render.
     *
     * §6.1 lists a flight centre, learning progress and a Ziyarah
     * companion. None has any data, and a portal full of permanently empty
     * panels — or plausible invented content — is the defect that put
     * fabricated social links on the live site.
     */
    public function test_empty_sections_do_not_render_at_all(): void
    {
        $this->enter($this->booking());

        $this->get('/en/portal')
            ->assertOk()
            ->assertDontSee('Your days')
            ->assertDontSee('Hotels');
    }

    // ── Documents ────────────────────────────────────────────────────────

    public function test_it_shows_document_and_approval_state(): void
    {
        $booking = $this->booking();
        $traveller = $booking->travellers->first()->traveller;

        Document::factory()->create([
            'traveller_id' => $traveller->getKey(),
            'type' => Document::PASSPORT,
            'status' => Document::VERIFIED,
        ]);

        $visa = VisaApplication::factory()->create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $traveller->getKey(),
        ]);
        $visa->transitionTo(VisaApplication::PREPARING);

        $this->enter($booking);

        $this->get('/en/portal/documents')
            ->assertOk()
            ->assertSee('Checked')
            ->assertSee('Being prepared')
            ->assertSee('Not started');
    }

    /**
     * The portal never hands a file back.
     *
     * A link sent over WhatsApp will be forwarded into a family group chat.
     * "Your passport is verified" belongs there; a passport scan does not.
     */
    public function test_it_does_not_hand_back_the_files(): void
    {
        $booking = $this->booking();
        $this->enter($booking);

        $this->get('/en/portal/documents')
            ->assertOk()
            ->assertSee('we do not show the files themselves')
            ->assertDontSee('/documents/')
            ->assertDontSee('download');
    }

    public function test_a_customer_can_send_a_passport(): void
    {
        $booking = $this->booking();
        $traveller = $booking->travellers->first()->traveller;
        $this->enter($booking);

        $this->post('/en/portal/documents', [
            'traveller_id' => $traveller->getKey(),
            'file' => UploadedFile::fake()->createWithContent('passport.pdf', 'a scan'),
            'expires_at' => now()->addYears(5)->toDateString(),
        ])->assertRedirect('/en/portal/documents');

        $document = Document::sole();

        $this->assertSame($traveller->getKey(), $document->traveller_id);
        // Uploading is not verifying: a document that arrives already ticked
        // is a document nobody checked.
        $this->assertSame(Document::PENDING, $document->status);
        $this->assertSame(1, $document->versions()->count());
    }

    /** The traveller id is a number in a form body until it is checked. */
    public function test_a_passport_cannot_be_filed_against_somebody_elses_traveller(): void
    {
        $mine = $this->booking();
        $stranger = $this->booking();

        $this->enter($mine);

        $this->post('/en/portal/documents', [
            'traveller_id' => $stranger->travellers->first()->traveller_id,
            'file' => UploadedFile::fake()->createWithContent('passport.pdf', 'a scan'),
        ])->assertForbidden();

        $this->assertSame(0, Document::count());
    }

    // ── Payments ─────────────────────────────────────────────────────────

    public function test_a_customer_can_send_a_transfer_slip(): void
    {
        $booking = $this->booking();
        $this->enter($booking);

        $this->post('/en/portal/payments', [
            'amount' => 10_000,
            'paid_at' => now()->subDay()->toDateString(),
            'payer_name' => 'Ibrahim Waheed',
            'slip' => UploadedFile::fake()->createWithContent('slip.pdf', 'a slip'),
        ])->assertRedirect('/en/portal');

        $payment = Payment::sole();

        $this->assertSame(1_000_000, $payment->amount_minor);
        $this->assertTrue($payment->hasSlip());
        // A claim, never money received: a portal that could mark its own
        // payments as received would confirm bookings for free.
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
        $this->assertSame(0, $booking->fresh()->paid_minor);
    }

    public function test_a_slip_needs_an_amount_and_a_file(): void
    {
        $this->enter($this->booking());

        $this->post('/en/portal/payments', [])
            ->assertSessionHasErrors(['amount', 'slip']);

        $this->assertSame(0, Payment::count());
    }

    public function test_uploads_can_be_switched_off(): void
    {
        config(['portal.uploads.enabled' => false]);

        $this->enter($this->booking());

        $this->post('/en/portal/payments', [
            'amount' => 10_000,
            'slip' => UploadedFile::fake()->createWithContent('slip.pdf', 'a slip'),
        ])->assertNotFound();
    }

    // ── Issuing and cancelling ───────────────────────────────────────────

    public function test_cancelling_kills_every_link_for_the_booking(): void
    {
        $booking = $this->booking();
        $first = $this->gate()->issue($booking);
        $second = $this->gate()->issue($booking);

        $this->assertSame(2, $this->gate()->revokeAllFor($booking));

        foreach ([$first, $second] as $token) {
            $this->get("/en/portal/enter/{$token}")->assertRedirect('/en/portal/locked');
        }
    }

    /** Cancelling one booking's links must not touch another's. */
    public function test_cancelling_is_scoped_to_one_booking(): void
    {
        $mine = $this->booking();
        $theirs = $this->booking();

        $this->gate()->issue($mine);
        $safe = $this->gate()->issue($theirs);

        $this->gate()->revokeAllFor($mine);

        $this->get("/en/portal/enter/{$safe}")->assertRedirect('/en/portal');
    }

    public function test_the_issuer_is_recorded(): void
    {
        $staff = User::factory()->create()->assignRole(Access::BOOKING_STAFF);

        $this->actingAs($staff);
        $this->gate()->issue($this->booking());

        $this->assertSame($staff->getKey(), PortalAccess::sole()->issued_by);
    }
}
