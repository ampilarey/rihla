<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\SeatHold;
use App\Models\Traveller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The public booking flow: package → departure → room → travellers → review
 * → seats held.
 *
 * Server-rendered throughout, because this traffic is a phone on mobile data
 * in Malé and a checkout that needs a bundle to load is a checkout that
 * fails for the people most likely to be booking. Every step here is
 * exercised with a plain form post.
 */
class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function package(int $seats = 10): Package
    {
        // Unique slug per call: two packages are needed to prove a departure
        // cannot be booked through another package's URL, and the column is
        // unique.
        $package = Package::factory()->create([
            'title' => ['en' => 'Shawwal Umrah'],
            'slug' => 'shawwal-umrah-'.Package::max('id') + 1,
        ]);

        $departure = Departure::factory()->withSeats($seats)->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(74),
        ]);

        PriceTier::create([
            'departure_id' => $departure->getKey(),
            'occupancy' => 'quad',
            'pax_type' => PriceTier::ADULT,
            'amount_minor' => 2_850_000,
        ]);

        return $package->refresh();
    }

    private function departureOf(Package $package): Departure
    {
        return $package->departures()->sole();
    }

    /** @return array<string, mixed> */
    private function party(int $count, array $overrides = []): array
    {
        $travellers = [];

        for ($i = 0; $i < $count; $i++) {
            $travellers[$i] = [
                'full_name' => "Traveller {$i}",
                'date_of_birth' => now()->subYears(35)->toDateString(),
                'gender' => 'male',
                ...($overrides[$i] ?? []),
            ];
        }

        return [
            'contact_name' => 'Aminath',
            'contact_phone' => '7771234',
            'contact_email' => 'aminath@example.mv',
            'travellers' => $travellers,
        ];
    }

    private function holdSeats(Package $package, int $seats = 2): void
    {
        $this->post("/en/packages/{$package->slug}/book", [
            'departure' => $this->departureOf($package)->getKey(),
            'occupancy' => 'quad',
            'seats' => $seats,
        ])->assertRedirect('/en/book/travellers');
    }

    // ── Step 1 ────────────────────────────────────────────────────────────

    public function test_the_package_page_offers_a_booking_link(): void
    {
        $package = $this->package();

        $this->get("/en/packages/{$package->slug}")
            ->assertOk()
            ->assertSee("/en/packages/{$package->slug}/book", escape: false);
    }

    /** Capacity of zero means nobody entered one, so there is nothing to sell. */
    public function test_a_departure_with_no_capacity_offers_no_booking_link(): void
    {
        $package = Package::factory()->create(['slug' => 'no-seats']);
        Departure::factory()->withoutCapacity()->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(70),
        ]);

        $this->get('/en/packages/no-seats')
            ->assertOk()
            ->assertDontSee('/en/packages/no-seats/book', escape: false);
    }

    public function test_the_first_step_renders_in_both_languages(): void
    {
        $package = $this->package();

        $this->get("/en/packages/{$package->slug}/book")->assertOk()->assertSee('Shawwal Umrah');
        $this->get("/dv/packages/{$package->slug}/book")->assertOk()->assertSee('Shawwal Umrah');
    }

    public function test_holding_seats_takes_them_off_the_departure(): void
    {
        $package = $this->package();

        $this->holdSeats($package, 3);

        $this->assertSame(3, $this->departureOf($package)->fresh()->capacity_held);
        $this->assertSame(1, SeatHold::count());
    }

    /**
     * The hold is anonymous until the travellers are entered: there is no
     * customer to attach a booking to yet, which is why seat_holds.booking_id
     * is nullable.
     */
    public function test_the_first_step_creates_no_booking_yet(): void
    {
        $this->holdSeats($this->package());

        $this->assertSame(0, Booking::count());
    }

    public function test_a_room_the_departure_does_not_price_is_refused(): void
    {
        $package = $this->package();

        $this->post("/en/packages/{$package->slug}/book", [
            'departure' => $this->departureOf($package)->getKey(),
            'occupancy' => 'single',
            'seats' => 1,
        ])->assertSessionHasErrors('occupancy');

        $this->assertSame(0, SeatHold::count());
    }

    public function test_more_seats_than_remain_is_refused_with_the_real_number(): void
    {
        $package = $this->package(seats: 2);

        $this->post("/en/packages/{$package->slug}/book", [
            'departure' => $this->departureOf($package)->getKey(),
            'occupancy' => 'quad',
            'seats' => 5,
        ])->assertSessionHasErrors('seats');

        $this->assertSame(0, $this->departureOf($package)->fresh()->capacity_held);
    }

    public function test_a_departure_belonging_to_another_package_is_refused(): void
    {
        $package = $this->package();
        $other = $this->package();

        $this->post("/en/packages/{$package->slug}/book", [
            'departure' => $this->departureOf($other)->getKey(),
            'occupancy' => 'quad',
            'seats' => 1,
        ])->assertSessionHasErrors('departure');
    }

    // ── Step 2 ────────────────────────────────────────────────────────────

    public function test_the_traveller_step_needs_a_live_hold(): void
    {
        $this->get('/en/book/travellers')->assertRedirect('/en/packages');
    }

    public function test_one_form_is_rendered_for_each_seat_held(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 3);

        $response = $this->get('/en/book/travellers')->assertOk();

        foreach ([0, 1, 2] as $index) {
            $response->assertSee("travellers[{$index}][full_name]", escape: false);
        }

        $response->assertDontSee('travellers[3][full_name]', escape: false);
    }

    public function test_submitting_the_travellers_creates_the_booking(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 2);

        $this->post('/en/book/travellers', $this->party(2))
            ->assertRedirect('/en/book/review');

        $booking = Booking::sole();

        $this->assertSame(2, $booking->seats);
        $this->assertSame(Booking::DRAFT, $booking->status);
        $this->assertSame('Aminath', Customer::sole()->name);
        $this->assertSame(2, Traveller::count());
        $this->assertSame(2, $booking->travellers()->count());
        $this->assertSame(5_700_000, $booking->total_minor);
        $this->assertNotNull($booking->package_snapshot);
    }

    /** The hold stops being anonymous, so that expiry can expire the booking too. */
    public function test_the_hold_is_attached_to_the_booking(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 1);

        $this->post('/en/book/travellers', $this->party(1));

        $this->assertSame(Booking::sole()->getKey(), SeatHold::sole()->booking_id);
    }

    public function test_the_first_traveller_is_the_lead(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 2);

        $this->post('/en/book/travellers', $this->party(2));

        $booking = Booking::with('travellers.traveller')->sole();

        $this->assertSame('Traveller 0', $booking->leadTraveller()->traveller->full_name);
    }

    public function test_the_number_of_travellers_must_match_the_seats_held(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 3);

        $this->post('/en/book/travellers', $this->party(2))
            ->assertSessionHasErrors('travellers');

        $this->assertSame(0, Booking::count());
    }

    /**
     * Mechanical, and needs nobody's policy. The mahram requirement is
     * deliberately not enforced here — it is a Saudi rule that belongs in
     * versioned configuration (plan §5.4b), and a half-remembered version of
     * it in a checkout would turn away bookings that are perfectly allowed.
     */
    public function test_a_party_of_only_children_is_refused(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 1);

        $this->post('/en/book/travellers', $this->party(1, [
            0 => ['date_of_birth' => now()->subYears(8)->toDateString()],
        ]))->assertSessionHasErrors('travellers');

        $this->assertSame(0, Booking::count());
    }

    public function test_a_child_travelling_with_an_adult_is_accepted(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 2);

        $this->post('/en/book/travellers', $this->party(2, [
            1 => ['date_of_birth' => now()->subYears(8)->toDateString()],
        ]))->assertRedirect('/en/book/review');

        $this->assertSame(1, Booking::count());
    }

    /** A departure that prices only adults prices everybody as an adult. */
    public function test_a_child_is_priced_as_an_adult_when_no_child_tier_exists(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 2);

        $this->post('/en/book/travellers', $this->party(2, [
            1 => ['date_of_birth' => now()->subYears(8)->toDateString()],
        ]));

        $this->assertSame(5_700_000, Booking::sole()->total_minor);
    }

    public function test_a_child_tier_is_used_when_the_departure_publishes_one(): void
    {
        $package = $this->package();
        PriceTier::create([
            'departure_id' => $this->departureOf($package)->getKey(),
            'occupancy' => 'quad',
            'pax_type' => PriceTier::CHILD,
            'amount_minor' => 1_500_000,
        ]);

        $this->holdSeats($package, 2);

        $this->post('/en/book/travellers', $this->party(2, [
            1 => ['date_of_birth' => now()->subYears(8)->toDateString()],
        ]));

        $this->assertSame(4_350_000, Booking::sole()->total_minor);
    }

    /** Age at travel, not at booking: the tier follows the departure date. */
    public function test_the_price_follows_the_age_on_the_departure_date(): void
    {
        $package = $this->package();
        $departure = $this->departureOf($package);
        PriceTier::create([
            'departure_id' => $departure->getKey(),
            'occupancy' => 'quad',
            'pax_type' => PriceTier::CHILD,
            'amount_minor' => 1_500_000,
        ]);

        $this->holdSeats($package, 2);

        // Eleven today — their twelfth birthday is thirty days away — and
        // twelve by the time the flight leaves in sixty.
        $this->post('/en/book/travellers', $this->party(2, [
            1 => ['date_of_birth' => now()->subYears(12)->addDays(30)->toDateString()],
        ]));

        $this->assertSame(5_700_000, Booking::sole()->total_minor,
            'A traveller who turns twelve before departure pays the adult fare.');
    }

    // ── Step 3 and the confirmation ───────────────────────────────────────

    public function test_the_review_shows_the_total(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 2);
        $this->post('/en/book/travellers', $this->party(2));

        $this->get('/en/book/review')
            ->assertOk()
            ->assertSee('MVR 57,000')
            ->assertSee('Traveller 0');
    }

    public function test_confirming_needs_the_checkbox(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 1);
        $this->post('/en/book/travellers', $this->party(1));

        $this->post('/en/book/review', [])->assertSessionHasErrors('confirmed');

        $this->assertSame(Booking::DRAFT, Booking::sole()->status);
    }

    public function test_confirming_holds_the_booking_and_records_the_transition(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 1);
        $this->post('/en/book/travellers', $this->party(1));

        $this->post('/en/book/review', ['confirmed' => '1'])
            ->assertRedirect('/en/book/confirmation');

        $booking = Booking::sole();

        $this->assertSame(Booking::HELD, $booking->status);
        $this->assertSame(Booking::HELD, $booking->transitions()->sole()->to_status);
    }

    public function test_the_confirmation_shows_the_reference(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 1);
        $this->post('/en/book/travellers', $this->party(1));
        $this->post('/en/book/review', ['confirmed' => '1']);

        $this->get('/en/book/confirmation')
            ->assertOk()
            ->assertSee(Booking::sole()->fresh()->reference);
    }

    /**
     * **No invented account number.** config/payments.php ships with no bank
     * details, and while that is true the confirmation must say nothing
     * about where to send money. A made-up account is not a placeholder: it
     * is an instruction to a customer to send money somewhere, and it is the
     * same class of defect as the fabricated social links that reached the
     * live site.
     */
    public function test_the_confirmation_invents_no_bank_details(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 1);
        $this->post('/en/book/travellers', $this->party(1));
        $this->post('/en/book/review', ['confirmed' => '1']);

        $this->get('/en/book/confirmation')
            ->assertOk()
            ->assertDontSee('Paying by bank transfer')
            ->assertDontSee('Account number');
    }

    public function test_the_confirmation_shows_the_transfer_details_once_they_exist(): void
    {
        config([
            'payments.bank.name' => 'Bank of Maldives',
            'payments.bank.account_name' => 'Rihla Travels Pvt Ltd',
            'payments.bank.accounts' => ['MVR' => '7730000123456'],
        ]);

        $package = $this->package();
        $this->holdSeats($package, 1);
        $this->post('/en/book/travellers', $this->party(1));
        $this->post('/en/book/review', ['confirmed' => '1']);

        $this->get('/en/book/confirmation')
            ->assertOk()
            ->assertSee('Paying by bank transfer')
            ->assertSee('7730000123456')
            // The booking reference is what matches the transfer to the
            // booking when it lands in the account.
            ->assertSee(Booking::sole()->fresh()->reference);
    }

    /**
     * Somebody returning to the tab an hour later needs their reference and a
     * way to reach Rihla, not a redirect that loses both.
     */
    public function test_the_confirmation_still_shows_the_reference_after_the_hold_lapses(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 1);
        $this->post('/en/book/travellers', $this->party(1));
        $this->post('/en/book/review', ['confirmed' => '1']);

        $this->travel(16)->minutes();

        $this->get('/en/book/confirmation')
            ->assertOk()
            ->assertSee(Booking::sole()->fresh()->reference)
            ->assertSee('no longer held', escape: false);
    }

    // ── Expiry ────────────────────────────────────────────────────────────

    public function test_a_lapsed_hold_sends_the_visitor_back_to_start_again(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 2);

        $this->travel(16)->minutes();

        $this->get('/en/book/travellers')
            ->assertRedirect('/en/packages')
            ->assertSessionHas('status');
    }

    public function test_a_lapsed_hold_cannot_be_used_to_create_a_booking(): void
    {
        $package = $this->package();
        $this->holdSeats($package, 2);

        $this->travel(16)->minutes();

        $this->post('/en/book/travellers', $this->party(2))->assertRedirect('/en/packages');

        $this->assertSame(0, Booking::count());
    }

    // ── Privacy ───────────────────────────────────────────────────────────

    /**
     * No identifier in any checkout URL. A booking reference in the path
     * would let anyone who guessed one read a stranger's passport number and
     * phone number.
     *
     * **Unauthenticated routes only**, which is the threat this guards
     * against: "anyone who guessed one". A staff route behind `auth` is
     * reached by somebody the booking policy has already been asked about,
     * and every screen in the admin panel carries a record id in its path —
     * `/staff/bookings/{record}/edit` among them. Narrowed when the invoice
     * download was added, because the substring match was catching
     * `staff-documents/booking/{booking}/invoice`; the public checkout and
     * portal routes, which are what this is about, are unaffected and the
     * test below proves it still bites on one.
     */
    public function test_no_checkout_url_carries_a_booking_identifier(): void
    {
        $offenders = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! str_contains($route->uri(), 'book')) {
                continue;
            }

            if (in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach (['reference', 'booking', 'hold', 'id'] as $parameter) {
                if (in_array($parameter, $route->parameterNames(), true)) {
                    $offenders[] = $route->uri();
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Checkout routes must not take a booking identifier:'], $offenders,
        )));
    }

    /**
     * The guard above, proved.
     *
     * It was narrowed to unauthenticated routes when the staff invoice
     * download was added, and a narrowed guard that nobody re-proves is a
     * guard that has quietly stopped working. This registers a public route
     * of exactly the shape the rule forbids and asserts it is caught.
     */
    public function test_that_guard_still_catches_a_public_route(): void
    {
        Route::get('/en/book/{booking}/peek', fn () => '')->name('test.peek');

        $caught = false;

        foreach (app('router')->getRoutes() as $route) {
            if (! str_contains($route->uri(), 'book') || in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }

            if (in_array('booking', $route->parameterNames(), true)) {
                $caught = true;
            }
        }

        $this->assertTrue($caught, 'The guard no longer catches a public route carrying a booking id.');
    }
}
