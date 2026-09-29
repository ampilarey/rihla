<?php

namespace Tests\Feature;

use App\Exceptions\CommissionNotSet;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayAccess;
use App\Services\Stays\Commission;
use App\Services\Stays\StayBooking;
use App\Services\Stays\StayGatekeeper;
use App\Support\Audience;
use App\Support\Services;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A guest books and manages a stay themselves — §16.7, §16 Phase 13.3.
 */
class StayCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        Services::save(['stays_guesthouses' => Services::ON]);
        RateLimiter::clear('stay-book');
        $this->travelTo(now()->setDate(2027, 1, 10));

        config([
            'payments.methods.bank_transfer.enabled' => true,
            'payments.bank.name' => 'Bank of Maldives',
            'payments.bank.account_name' => 'Rihla Travels',
            'payments.bank.accounts' => ['USD' => '7730000012345'],
        ]);

        $this->property = Property::factory()->create([
            'currency' => 'USD',
            'min_nights' => 1,
            'deposit_pct' => 30,
            'free_cancel_days' => 14,
            'partner_id' => Partner::factory()->commissionBased(15)->create()->id,
        ]);

        $this->room = RoomType::factory()->create([
            'property_id' => $this->property->id,
            'quantity' => 1,
            'sleeps' => 2,
            'base_rate_minor' => 10000,
            'local_rate_minor' => 90000,
        ]);
    }

    private function bookUrl(array $query = []): string
    {
        return route('stays.book', ['locale' => 'en', 'property' => $this->property->slug] + $query + [
            'room' => $this->room->id, 'from' => '2027-03-03', 'to' => '2027-03-05', 'adults' => 2, 'audience' => 'tourist',
        ]);
    }

    /** @return array<string, mixed> */
    private function details(array $overrides = []): array
    {
        return $overrides + [
            'room' => $this->room->id,
            'from' => '2027-03-03',
            'to' => '2027-03-05',
            'adults' => 2,
            'children' => 0,
            'audience' => 'tourist',
            'name' => 'Sara Visitor',
            'email' => 'sara@example.test',
            'phone' => '+44 7700 900123',
            'citizenship' => 'other',
            'special_requests' => 'Late arrival on the last ferry.',
            'accept' => '1',
        ];
    }

    private function book(array $overrides = []): TestResponse
    {
        return $this->post(route('stays.book.store', ['locale' => 'en', 'property' => $this->property->slug]), $this->details($overrides));
    }

    // ── The booking page ─────────────────────────────────────────────────

    public function test_the_page_restates_the_quote_and_the_terms(): void
    {
        $this->get($this->bookUrl())
            ->assertOk()
            ->assertSee('USD 200', false)
            ->assertSee('Nothing until the host confirms')
            ->assertSee('We will confirm with the host within 24 hours. You pay nothing until then.')
            ->assertSee('name="website"', false);
    }

    public function test_it_takes_no_bookings_while_the_door_is_only_coming_soon(): void
    {
        Services::save(['stays_guesthouses' => Services::COMING_SOON]);

        $this->get($this->bookUrl())->assertNotFound();
        $this->book()->assertNotFound();
        $this->assertSame(0, Stay::count());
    }

    public function test_a_room_of_another_property_cannot_be_booked_through_this_one(): void
    {
        $elsewhere = RoomType::factory()->create();

        $this->get($this->bookUrl(['room' => $elsewhere->id]))->assertNotFound();
    }

    public function test_without_dates_the_guest_is_sent_back_to_choose_them(): void
    {
        $this->get(route('stays.book', ['locale' => 'en', 'property' => $this->property->slug, 'room' => $this->room->id]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Choose your dates first, then pick a room.');
    }

    // ── Making the stay ──────────────────────────────────────────────────

    public function test_a_request_is_made_with_the_commission_frozen_and_the_guest_let_in(): void
    {
        $this->book()->assertRedirect(route('my-stay.home', ['locale' => 'en']))->assertSessionHas('stay_link');

        $stay = Stay::sole();

        $this->assertSame(Stay::REQUESTED, $stay->status);
        $this->assertSame(Commission::MARKETPLACE, $stay->source);
        $this->assertSame(Stay::VIA_GUEST, $stay->created_via);
        $this->assertSame(20000, $stay->total_minor);
        $this->assertSame(15, $stay->commission_pct_snapshot);
        $this->assertSame(3000, $stay->commission_minor);
        $this->assertSame(17000, $stay->host_net_minor);
        $this->assertSame(Partner::COMMISSION_DEPOSIT, $stay->settlement_model_snapshot);
        $this->assertSame('Late arrival on the last ferry.', $stay->special_requests);
        $this->assertSame('sara@example.test', $stay->customer->email);
        $this->assertSame(1, StayAccess::where('stay_id', $stay->id)->count());

        $this->get(route('my-stay.home', ['locale' => 'en']))
            ->assertOk()
            ->assertSee($stay->reference)
            ->assertSee('We have asked the host.')
            ->assertSee('Keep this link');
    }

    public function test_an_instant_book_listing_is_held_and_asks_for_the_deposit(): void
    {
        $this->property->update(['instant_book' => true]);

        $this->book();

        $stay = Stay::sole();
        $this->assertSame(Stay::HELD, $stay->status);

        $this->get(route('my-stay.home', ['locale' => 'en']))
            ->assertSee('To pay now: USD 60', false)
            ->assertSee('7730000012345')
            ->assertSee('Send us your transfer slip');
    }

    /** The last room, asked for twice: one stay holds it, the other is told. */
    public function test_two_guests_for_the_last_room_one_gets_it(): void
    {
        $this->property->update(['instant_book' => true]);

        $this->book();
        $this->book(['email' => 'second@example.test'])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, Stay::count());
        $this->assertSame(1, Customer::count(), 'A refused booking leaves no customer behind.');
    }

    /** Nationality is the answer; the toggle on the listing was a guess. */
    public function test_a_maldivian_on_the_tourist_price_is_re_quoted_not_booked(): void
    {
        $this->book(['citizenship' => 'maldivian'])
            ->assertRedirectContains('/stays/'.$this->property->slug.'/book?')
            ->assertRedirectContains('audience=local')
            ->assertSessionHas('status');

        $this->assertSame(0, Stay::count());

        $this->book(['citizenship' => 'maldivian', 'audience' => 'local']);

        $stay = Stay::sole();
        $this->assertSame(Audience::LOCAL, $stay->audience);
        $this->assertSame('MVR', $stay->currency);
        $this->assertSame(180000, $stay->total_minor);
    }

    /** §16.9: never recorded as though it earned nothing. */
    public function test_a_host_with_no_commission_stated_cannot_be_booked_online(): void
    {
        $this->property->partner->update(['pricing_model' => Partner::COMMISSION, 'commission_pct' => null]);
        config(['marketplace.default_commission_pct' => null]);

        $this->book()->assertRedirect()->assertSessionHas('status', 'This place cannot be booked online just yet. Message us and we will book it for you.');

        $this->assertSame(0, Stay::count());
        $this->assertSame(0, Customer::count());
    }

    /**
     * Refused before anything is written, whoever calls the service. The
     * checkout wraps its call in a transaction; a staff or host screen
     * calling StayBooking directly would not, and a check made after the
     * insert would leave a stay behind with no commission on it.
     */
    public function test_the_booking_service_refuses_before_writing_a_stay(): void
    {
        $this->property->partner->update(['commission_pct' => null]);
        config(['marketplace.default_commission_pct' => null]);

        try {
            app(StayBooking::class)->request(
                Customer::factory()->create(), $this->room,
                CarbonImmutable::parse('2027-03-03'), CarbonImmutable::parse('2027-03-05'),
                details: ['source' => Commission::MARKETPLACE],
            );
            $this->fail('A marketplace stay with no commission was accepted.');
        } catch (CommissionNotSet) {
            $this->assertSame(0, Stay::count());
        }

        // A stay the host or the office enters is not the marketplace's, and
        // carries no commission to refuse over.
        $direct = app(StayBooking::class)->request(
            Customer::factory()->create(), $this->room,
            CarbonImmutable::parse('2027-03-03'), CarbonImmutable::parse('2027-03-05'),
            details: ['source' => 'phone', 'created_via' => Stay::VIA_STAFF],
        );

        $this->assertNull($direct->commission_pct_snapshot);
        $this->assertSame(Stay::VIA_STAFF, $direct->created_via);
    }

    public function test_the_configured_default_fills_in_a_missing_commission(): void
    {
        $this->property->partner->update(['commission_pct' => null]);
        config(['marketplace.default_commission_pct' => 10]);

        $this->book();

        $this->assertSame(10, Stay::sole()->commission_pct_snapshot);
    }

    /** Rihla's own rooms and net-rate hosts carry no percentage. */
    public function test_rihlas_own_rooms_carry_no_commission(): void
    {
        $this->property->update(['partner_id' => Partner::rihla()->id]);

        $this->book();

        $this->assertSame(0, Stay::sole()->commission_minor);
        $this->assertSame(Partner::FULL_COLLECTION, Stay::sole()->settlement_model_snapshot);
    }

    public function test_the_honeypot_is_thanked_and_nothing_is_made(): void
    {
        $this->book(['website' => 'http://spam.example'])->assertRedirect();

        $this->assertSame(0, Stay::count());
        $this->assertSame(0, Customer::count());
    }

    public function test_the_terms_must_be_accepted(): void
    {
        $this->book(['accept' => null])->assertSessionHasErrors('accept');
        $this->assertSame(0, Stay::count());
    }

    public function test_more_guests_than_the_room_sleeps_is_refused(): void
    {
        $this->book(['adults' => 3])->assertSessionHasErrors('adults');
        $this->assertSame(0, Stay::count());
    }

    public function test_booking_is_throttled_per_address(): void
    {
        foreach (range(1, 10) as $n) {
            $this->book(['website' => 'x']);
        }

        $this->book()->assertStatus(429);
    }

    // ── The guest's page ─────────────────────────────────────────────────

    public function test_the_page_is_locked_without_a_session(): void
    {
        $this->get(route('my-stay.home', ['locale' => 'en']))->assertRedirect(route('my-stay.locked', ['locale' => 'en']));
    }

    public function test_a_link_opens_the_stay_and_a_dead_one_says_why(): void
    {
        $stay = Stay::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->room->id]);
        $token = app(StayGatekeeper::class)->issue($stay);

        $this->get(route('my-stay.enter', ['locale' => 'en', 'token' => $token]))->assertRedirect(route('my-stay.home', ['locale' => 'en']));
        $this->get(route('my-stay.home', ['locale' => 'en']))->assertOk()->assertSee($stay->reference);

        app(StayGatekeeper::class)->revokeAllFor($stay);
        $this->flushSession();

        $this->get(route('my-stay.enter', ['locale' => 'en', 'token' => $token]))
            ->assertRedirect(route('my-stay.locked', ['locale' => 'en']))
            ->assertSessionHas('stay_problem', 'That link has been cancelled. Message us and we will send a new one.');

        $this->get(route('my-stay.enter', ['locale' => 'en', 'token' => 'not-a-real-token']))
            ->assertSessionHas('stay_problem', 'That link is not one of ours. Check it was copied in full.');
    }

    /** Only the hash is stored — a leaked table is not a set of working links. */
    public function test_only_the_hash_of_a_link_is_stored(): void
    {
        $stay = Stay::factory()->create();
        $token = app(StayGatekeeper::class)->issue($stay);

        $this->assertSame(hash('sha256', $token), StayAccess::sole()->token_hash);
        $this->assertStringNotContainsString($token, (string) json_encode(StayAccess::sole()->getAttributes()));
    }

    public function test_a_slip_is_recorded_as_a_claim_not_as_money(): void
    {
        Storage::fake('local');
        Storage::fake(config('payments.slips.disk', 'local'));
        $this->property->update(['instant_book' => true]);
        $this->book();

        $this->post(route('my-stay.payments.store', ['locale' => 'en']), [
            'amount' => 60,
            'payer_name' => 'Sara Visitor',
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertRedirect(route('my-stay.home', ['locale' => 'en']));

        $payment = Payment::sole();
        $stay = Stay::sole();

        $this->assertTrue($payment->payable->is($stay));
        $this->assertSame(6000, $payment->amount_minor);
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
        $this->assertSame(0, $stay->fresh()->paid_minor, 'Nothing is paid until Finance says it arrived.');
        $this->assertSame(Stay::HELD, $stay->fresh()->status);
    }

    /** Inside the window agreed on the day: cancelled, and the room goes back. */
    public function test_a_guest_cancels_free_inside_the_window_and_the_room_is_released(): void
    {
        $this->property->update(['instant_book' => true]);
        $this->book();

        $this->get(route('my-stay.home', ['locale' => 'en']))->assertSee('Cancel this stay');

        $this->post(route('my-stay.cancel', ['locale' => 'en']))
            ->assertRedirect(route('my-stay.home', ['locale' => 'en']))
            ->assertSessionHas('status', 'Your stay is cancelled. You owe nothing.');

        $this->assertSame(Stay::CANCELLED, Stay::sole()->status);

        // The room is free again for somebody else.
        $this->book(['email' => 'next@example.test']);
        $this->assertSame(1, Stay::where('status', Stay::HELD)->count());
    }

    /** Outside it: no button, a reason and a person — and the route refuses. */
    public function test_outside_the_window_the_guest_is_sent_to_a_person(): void
    {
        $this->property->update(['instant_book' => true]);
        $this->book();

        $this->travelTo(now()->setDate(2027, 2, 25));
        // The page session lasts hours, not weeks; come back by the link.
        app(StayGatekeeper::class)->open(Stay::sole()->id);

        $this->get(route('my-stay.home', ['locale' => 'en']))
            ->assertDontSee('Cancel this stay')
            ->assertSee('The free cancellation window has passed');

        $this->post(route('my-stay.cancel', ['locale' => 'en']))->assertForbidden();
        $this->assertSame(Stay::HELD, Stay::sole()->status);
    }

    /** A pilgrim-portal session never opens a stay. */
    public function test_a_portal_session_is_not_a_stay_session(): void
    {
        $this->withSession(['portal.booking' => 1, 'portal.until' => now()->addHour()->timestamp])
            ->get(route('my-stay.home', ['locale' => 'en']))
            ->assertRedirect(route('my-stay.locked', ['locale' => 'en']));
    }

    // ── The listing's Book button ────────────────────────────────────────

    public function test_the_listing_offers_book_only_for_a_free_quoted_room(): void
    {
        $this->get('/en/stays/'.$this->property->slug)->assertDontSee('Book this room');

        $this->get('/en/stays/'.$this->property->slug.'?from=2027-03-03&to=2027-03-05&guests=2')
            ->assertSee('Book this room')
            ->assertSee(route('stays.book', ['locale' => 'en', 'property' => $this->property->slug, 'room' => $this->room->id, 'from' => '2027-03-03', 'to' => '2027-03-05', 'adults' => 2, 'audience' => 'tourist']));

        Services::save(['stays_guesthouses' => Services::COMING_SOON]);

        $this->get('/en/stays/'.$this->property->slug.'?from=2027-03-03&to=2027-03-05&guests=2')->assertDontSee('Book this room');
    }
}
