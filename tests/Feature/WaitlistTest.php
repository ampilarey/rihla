<?php

namespace Tests\Feature;

use App\Filament\Resources\Waitlist\Pages\ListWaitlistEntries;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Booking\SeatAllocator;
use App\Services\Booking\Waitlist;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The queue for a full departure.
 *
 * A sold-out departure used to be a dead end: the page said "Fully booked"
 * and the visitor left. Seats do come back — a hold lapses, a passport turns
 * out to be expired, a family cancels — and nobody was told.
 */
class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    private function package(int $seats = 2): Package
    {
        $package = Package::factory()->create(['slug' => 'shawwal', 'title' => ['en' => 'Shawwal Umrah']]);

        $departure = Departure::factory()->withSeats($seats)->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(74),
        ]);

        PriceTier::create([
            'departure_id' => $departure->getKey(), 'occupancy' => 'quad', 'amount_minor' => 2_850_000,
        ]);

        return $package->refresh();
    }

    private function departure(Package $package): Departure
    {
        return $package->departures()->sole();
    }

    private function waitlist(): Waitlist
    {
        return app(Waitlist::class);
    }

    private function join(Departure $departure, string $name = 'Aminath', int $seats = 1): WaitlistEntry
    {
        return $this->waitlist()->join(
            $departure,
            Customer::factory()->create(['name' => $name]),
            $seats,
        );
    }

    /** Fill the departure so there is something to wait for. */
    private function sellOut(Departure $departure): void
    {
        $booking = Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => $departure->capacity_total,
        ]);

        app(SeatAllocator::class)->hold($departure, $departure->capacity_total, $booking);
        $booking->transitionTo(Booking::HELD);
    }

    // ── Joining ───────────────────────────────────────────────────────────

    public function test_a_sold_out_departure_offers_the_waiting_list(): void
    {
        $package = $this->package();
        $this->sellOut($this->departure($package));

        $this->get('/en/packages/shawwal')
            ->assertOk()
            ->assertSee('Join the waiting list')
            ->assertDontSee('Book now');
    }

    public function test_a_departure_with_seats_left_offers_booking_not_a_queue(): void
    {
        $this->package(10);

        $this->get('/en/packages/shawwal')
            ->assertOk()
            ->assertSee('Book now')
            ->assertDontSee('Join the waiting list');
    }

    public function test_somebody_can_join_from_the_package_page(): void
    {
        $package = $this->package();
        $departure = $this->departure($package);
        $this->sellOut($departure);

        // Following the redirect, because asserting the session key alone
        // passed while nothing on the page rendered it — the form appeared
        // to do nothing at all. Found by submitting it and reading the page
        // that came back.
        $this->followingRedirects()
            ->post('/en/packages/shawwal/waitlist', [
                'departure' => $departure->getKey(),
                'name' => 'Aminath Ibrahim',
                'phone' => '7771234',
                'seats' => 2,
            ])
            ->assertOk()
            ->assertSee('Shawwal Umrah')
            ->assertSee('You are on the waiting list');

        $entry = WaitlistEntry::sole();

        $this->assertSame(WaitlistEntry::WAITING, $entry->status);
        $this->assertSame(2, $entry->seats);
        $this->assertSame('Aminath Ibrahim', $entry->customer->name);
    }

    /**
     * An impatient tap or a browser retrying a POST would otherwise queue
     * the same family twice and offer them seats twice, while the party
     * behind them waits. Matched on the phone number, which is what a
     * Maldivian customer gives and what staff will ring.
     */
    public function test_submitting_the_form_twice_does_not_queue_twice(): void
    {
        $package = $this->package();
        $departure = $this->departure($package);
        $this->sellOut($departure);

        $payload = [
            'departure' => $departure->getKey(),
            'name' => 'Aminath Ibrahim',
            'phone' => '7771234',
            'seats' => 2,
        ];

        $this->post('/en/packages/shawwal/waitlist', $payload);
        $this->post('/en/packages/shawwal/waitlist', [...$payload, 'seats' => 3]);

        $this->assertSame(1, WaitlistEntry::count());
        $this->assertSame(3, WaitlistEntry::sole()->seats, 'The second submission should update the party size.');
        $this->assertSame(1, Customer::where('phone', '7771234')->count());
    }

    /** Two entries for one family would be two offers and two sets of held seats. */
    public function test_joining_twice_updates_the_entry_rather_than_queueing_again(): void
    {
        $package = $this->package();
        $departure = $this->departure($package);
        $customer = Customer::factory()->create();

        $this->waitlist()->join($departure, $customer, 1);
        $this->waitlist()->join($departure, $customer, 3);

        $this->assertSame(1, WaitlistEntry::count());
        $this->assertSame(3, WaitlistEntry::sole()->seats);
    }

    // ── Promotion ─────────────────────────────────────────────────────────

    /**
     * The point of the whole feature: a cancellation reaches the queue
     * without anybody remembering to look.
     */
    public function test_cancelling_a_booking_offers_the_seats_to_the_queue(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);

        $entry = $this->join($departure, 'Waiting', seats: 2);

        $booking = Booking::sole();
        app(SeatAllocator::class)->release($booking->seatHolds()->sole());

        $entry->refresh();

        $this->assertSame(WaitlistEntry::OFFERED, $entry->status);
        $this->assertNotNull($entry->seat_hold_id);
        $this->assertTrue($entry->offerIsLive());
    }

    /** An offer holds the seats. Flagging somebody "next" and leaving the seats on sale is a race they lose. */
    public function test_an_offer_takes_the_seats_off_the_departure(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);
        $this->join($departure, 'Waiting', seats: 2);

        app(SeatAllocator::class)->release(Booking::sole()->seatHolds()->sole());

        $this->assertSame(0, $departure->fresh()->seats_remaining,
            'The seats must not be back on sale while they are offered.');
    }

    public function test_a_lapsed_hold_reaching_the_command_also_offers_the_seats(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);
        $entry = $this->join($departure, 'Waiting', seats: 2);

        $this->travel(16)->minutes();
        $this->artisan('bookings:expire-holds')->assertSuccessful();

        $this->assertSame(WaitlistEntry::OFFERED, $entry->fresh()->status);
    }

    /**
     * The reclaim inside a booking's own hold must not promote: those seats
     * are being taken by the person who triggered the reclaim, and offering
     * them away would take them out from under that customer.
     */
    public function test_booking_the_seats_a_lapsed_hold_released_does_not_promote(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);
        $entry = $this->join($departure, 'Waiting', seats: 2);

        $this->travel(16)->minutes();

        // Somebody books the seats directly, which reclaims the lapsed hold
        // inside the same lock.
        app(SeatAllocator::class)->hold($departure->fresh(), 2);

        $this->assertSame(WaitlistEntry::WAITING, $entry->fresh()->status,
            'The customer taking the seats must keep them.');
    }

    /** Oldest first. */
    public function test_the_earliest_entry_is_offered_first(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);

        $first = $this->join($departure, 'First', seats: 2);
        $this->travel(1)->minutes();
        $second = $this->join($departure, 'Second', seats: 2);

        app(SeatAllocator::class)->release(Booking::sole()->seatHolds()->sole());

        $this->assertSame(WaitlistEntry::OFFERED, $first->fresh()->status);
        $this->assertSame(WaitlistEntry::WAITING, $second->fresh()->status);
    }

    /**
     * The one departure from strict order, and it is deliberate: holding two
     * seats empty for a party of four who may never answer serves nobody.
     */
    public function test_a_party_too_large_for_the_seats_is_skipped(): void
    {
        $package = $this->package(4);
        $departure = $this->departure($package);

        // Two bookings of two seats each fill the departure, so exactly two
        // seats can be released later.
        $holds = [];

        foreach ([1, 2] as $n) {
            $booking = Booking::factory()->create([
                'customer_id' => Customer::factory()->create()->getKey(),
                'departure_id' => $departure->getKey(),
                'seats' => 2,
            ]);
            $holds[] = app(SeatAllocator::class)->hold($departure, 2, $booking);
        }

        $this->assertSame(0, $departure->fresh()->seats_remaining);

        $big = $this->join($departure, 'Party of four', seats: 4);
        $this->travel(1)->minutes();
        $small = $this->join($departure, 'Party of two', seats: 2);

        // Two seats come back — not enough for the party in front.
        app(SeatAllocator::class)->release($holds[0]);

        $this->assertSame(WaitlistEntry::WAITING, $big->fresh()->status,
            'A party that does not fit keeps its place rather than being burned.');
        $this->assertSame(WaitlistEntry::OFFERED, $small->fresh()->status,
            'The next party that fits is offered instead of the seats sitting empty.');
    }

    // ── Offers expiring ───────────────────────────────────────────────────

    /**
     * An offer nobody answered is over and the next person gets a turn.
     * Returning the entry to "waiting" would hand it the same seats again
     * for ever, and nobody behind it would ever be reached.
     */
    public function test_an_unanswered_offer_expires_and_the_next_party_is_offered(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);

        $first = $this->join($departure, 'First', seats: 2);
        $this->travel(1)->minutes();
        $second = $this->join($departure, 'Second', seats: 2);

        app(SeatAllocator::class)->release(Booking::sole()->seatHolds()->sole());
        $this->assertSame(WaitlistEntry::OFFERED, $first->fresh()->status);

        // The offer window passes and the seats go back.
        $this->travel(25)->hours();
        $this->artisan('bookings:expire-holds')->assertSuccessful();

        $this->assertSame(WaitlistEntry::EXPIRED, $first->fresh()->status);
        $this->assertSame(WaitlistEntry::OFFERED, $second->fresh()->status);
    }

    // ── Claiming ──────────────────────────────────────────────────────────

    public function test_the_claim_link_drops_the_party_into_the_checkout(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);
        $entry = $this->join($departure, 'Waiting', seats: 2);

        app(SeatAllocator::class)->release(Booking::sole()->seatHolds()->sole());

        $url = $this->waitlist()->claimUrl($entry->fresh());

        $this->assertNotNull($url);

        $this->get($url)->assertRedirect('/en/book/travellers');
        $this->get('/en/book/travellers')->assertOk()->assertSee('Who is travelling?');
    }

    /** It hands over held seats, so it must not be guessable. */
    public function test_an_unsigned_claim_link_is_refused(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);
        $entry = $this->join($departure, 'Waiting', seats: 2);

        app(SeatAllocator::class)->release(Booking::sole()->seatHolds()->sole());

        $this->get("/en/waitlist/claim/{$entry->getKey()}")->assertForbidden();
    }

    public function test_a_claim_link_opened_after_the_offer_lapsed_says_so(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);
        $entry = $this->join($departure, 'Waiting', seats: 2);

        app(SeatAllocator::class)->release(Booking::sole()->seatHolds()->sole());

        // A signed link that has not itself expired, for an offer that has.
        $url = URL::temporarySignedRoute('waitlist.claim', now()->addDays(3), [
            'locale' => 'en', 'entry' => $entry->getKey(),
        ]);

        $this->travel(25)->hours();

        $this->get($url)
            ->assertRedirect('/en/packages/shawwal')
            ->assertSessionHas('status');
    }

    // ── The admin screen ──────────────────────────────────────────────────

    /**
     * This screen *is* the notification channel: no SMTP is configured and
     * there is no WhatsApp API, so an offer has to appear as work with a
     * link somebody can paste.
     */
    public function test_booking_staff_see_the_queue_and_the_claim_link(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);
        $this->join($departure, 'Aminath Waiting', seats: 2);

        app(SeatAllocator::class)->release(Booking::sole()->seatHolds()->sole());

        Livewire::actingAs(
            User::factory()->create()->assignRole(Access::BOOKING_STAFF),
        )
            ->test(ListWaitlistEntries::class)
            ->assertOk()
            ->assertSee('Aminath Waiting')
            // The cell shows a word; the URL lives on the copy button, so
            // this asserts the affordance is offered rather than scraping a
            // signed link out of the markup.
            ->assertSee('Claim link')
            ->assertSee('Copy link');

        // And that the link itself is real, signed and points at the claim
        // route — the thing staff will actually paste.
        $url = $this->waitlist()->claimUrl(WaitlistEntry::sole());

        $this->assertNotNull($url);
        $this->assertStringContainsString('/waitlist/claim/', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    /** The queue holds names and phone numbers, which are not content. */
    public function test_the_content_manager_cannot_reach_the_queue(): void
    {
        $this->actingAs(
            User::factory()->create()->assignRole(Access::CONTENT_MANAGER),
        )
            ->get('/staff/waitlist/waitlist-entries')
            ->assertForbidden();
    }

    /** Removing an entry must put its held seats back, not strand them. */
    public function test_removing_an_offered_entry_returns_its_seats(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);
        $entry = $this->join($departure, 'Waiting', seats: 2);

        app(SeatAllocator::class)->release(Booking::sole()->seatHolds()->sole());
        $this->assertSame(0, $departure->fresh()->seats_remaining);

        $this->waitlist()->cancel($entry->fresh());

        $this->assertSame(WaitlistEntry::CANCELLED, $entry->fresh()->status);
        $this->assertSame(2, $departure->fresh()->seats_remaining);
    }

    /** Booking through a claim link must stop the entry showing as waiting. */
    public function test_completing_the_checkout_converts_the_entry(): void
    {
        $package = $this->package(2);
        $departure = $this->departure($package);
        $this->sellOut($departure);
        $entry = $this->join($departure, 'Waiting', seats: 1);

        app(SeatAllocator::class)->release(Booking::sole()->seatHolds()->sole());

        $this->get($this->waitlist()->claimUrl($entry->fresh()));

        $this->post('/en/book/travellers', [
            'contact_name' => 'Aminath',
            'contact_phone' => '7771234',
            'travellers' => [
                ['full_name' => 'Aminath', 'date_of_birth' => now()->subYears(30)->toDateString()],
            ],
        ])->assertRedirect('/en/book/review');

        $entry->refresh();

        $this->assertSame(WaitlistEntry::CONVERTED, $entry->status);
        $this->assertNotNull($entry->booking_id);
    }
}
