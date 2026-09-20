<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\ItineraryItem;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\Trip;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public package pages.
 *
 * Additive: /trips and /trips/{slug} are untouched. A package is the product
 * and a departure one dated run of it, which is what makes seats, a
 * countdown, an itinerary and hotel distances possible at all.
 */
class PackagePagesTest extends TestCase
{
    use RefreshDatabase;

    private function packageWithDeparture(array $departure = []): Package
    {
        $package = Package::factory()->create(['title' => ['en' => 'Shawwal Umrah']]);

        Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->addDays(33),
            'date_end' => now()->addDays(43),
            ...$departure,
        ]);

        return $package->refresh();
    }

    public function test_the_listing_renders_in_both_languages(): void
    {
        $this->packageWithDeparture();

        $this->get('/en/packages')->assertOk()->assertSee('Shawwal Umrah');
        $this->get('/dv/packages')->assertOk()->assertSee('Shawwal Umrah');
    }

    public function test_a_package_page_shows_its_departure(): void
    {
        $package = $this->packageWithDeparture(['airline' => 'Emirates']);

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertSee('Shawwal Umrah')
            ->assertSee('Emirates');
    }

    public function test_an_unpublished_package_is_not_reachable(): void
    {
        $package = Package::factory()->unpublished()->create();

        $this->get('/en/packages/'.$package->slug)->assertNotFound();
        $this->get('/en/packages')->assertOk()->assertDontSee($package->slug);
    }

    public function test_an_unpublished_departure_is_hidden_but_its_package_still_shows(): void
    {
        $package = $this->packageWithDeparture(['is_published' => false, 'airline' => 'Secret Air']);

        // The package is still listed — the page is also how somebody asks
        // for the next run.
        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertDontSee('Secret Air')
            ->assertSee('No dates are announced');
    }

    public function test_a_departure_that_has_left_is_not_advertised(): void
    {
        $package = Package::factory()->create();
        Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->subMonth(),
            'date_end' => now()->subMonth()->addDays(10),
            'airline' => 'Last Season Air',
        ]);

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertDontSee('Last Season Air');
    }

    // ── The seats bar ────────────────────────────────────────────────────

    public function test_the_seats_bar_reports_what_is_left(): void
    {
        $package = $this->packageWithDeparture(['capacity_total' => 24, 'capacity_confirmed' => 18]);

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertSee('6 seats left')
            ->assertSee('18 / 24');
    }

    /**
     * A departure with no capacity recorded draws no bar.
     *
     * Every backfilled departure starts this way, because `trips` had nowhere
     * to put a seat count. "0 of 0 seats" and a full bar would both be claims
     * the data does not support.
     */
    public function test_no_bar_is_drawn_when_nobody_has_recorded_a_seat_count(): void
    {
        $package = $this->packageWithDeparture(['capacity_total' => 0, 'capacity_confirmed' => 0]);

        $response = $this->get('/en/packages/'.$package->slug)->assertOk();

        $response->assertDontSee('seats left');
        $response->assertDontSee('Fully booked');
    }

    public function test_a_full_departure_says_so_rather_than_showing_zero_left(): void
    {
        $package = $this->packageWithDeparture(['capacity_total' => 24, 'capacity_confirmed' => 24]);

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertSee('Fully booked')
            ->assertDontSee('0 seats left');
    }

    // ── Countdown, hotels, itinerary, price ──────────────────────────────

    public function test_the_countdown_counts_down_and_then_stops(): void
    {
        $package = $this->packageWithDeparture();

        $this->get('/en/packages/'.$package->slug)->assertOk()->assertSee('33 days to go');
    }

    public function test_hotel_distance_is_shown_in_metres_and_minutes(): void
    {
        $package = $this->packageWithDeparture();

        DepartureHotel::create([
            'departure_id' => $package->publishedDepartures->first()->id,
            'city' => 'makkah',
            'name' => 'Swissotel Al Maqam',
            'distance_metres' => 300,
            'walk_minutes' => 4,
        ]);

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertSee('Swissotel Al Maqam')
            ->assertSee('300 m')
            ->assertSee('4 min walk');
    }

    public function test_the_itinerary_is_listed_day_by_day(): void
    {
        $package = $this->packageWithDeparture();

        ItineraryItem::create([
            'departure_id' => $package->publishedDepartures->first()->id,
            'day_number' => 2,
            'title' => ['en' => 'Ziyarah in Madinah'],
        ]);

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertSee('Day 2')
            ->assertSee('Ziyarah in Madinah');
    }

    public function test_prices_are_listed_per_occupancy_in_rufiyaa(): void
    {
        $package = $this->packageWithDeparture();
        $departure = $package->publishedDepartures->first();

        foreach ([['quad', 22_000], ['single', 41_000]] as [$occupancy, $major]) {
            PriceTier::create([
                'departure_id' => $departure->id,
                'occupancy' => $occupancy,
                'amount_minor' => Money::ofMajor($major)->minor,
            ]);
        }

        // Displayed in whole rufiyaa, stored in laari. If the page ever shows
        // 2,200,000 the conversion has been dropped on the way out.
        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertSee('MVR 22,000')
            ->assertSee('MVR 41,000')
            ->assertDontSee('2,200,000');
    }

    /** One click, with the package already named — how Maldivians enquire. */
    public function test_the_whatsapp_link_names_the_package(): void
    {
        $package = $this->packageWithDeparture();

        $html = $this->get('/en/packages/'.$package->slug)->assertOk()->getContent();

        $this->assertStringContainsString('wa.me/', $html);
        $this->assertStringContainsString(rawurlencode('Shawwal Umrah'), $html);
    }

    // ── The additive guarantee ───────────────────────────────────────────

    /**
     * /trips is untouched. Packages are additive until a full season has run
     * on the new model; nothing redirects between the two yet.
     */
    public function test_the_trips_pages_still_work(): void
    {
        $trip = Trip::create([
            'title' => ['en' => 'A published trip'],
            'slug' => 'a-published-trip',
            'date_start' => now()->addMonth(),
            'date_end' => now()->addMonth()->addDays(10),
            'status' => Trip::STATUS_UPCOMING,
            'is_published' => true,
        ]);

        $this->get('/en/trips')->assertOk()->assertSee('A published trip');
        $this->get('/en/trips/'.$trip->slug)->assertOk();
    }

    public function test_packages_are_reachable_from_the_navigation(): void
    {
        // A page nothing links to is a page nobody visits.
        $this->get('/en/trips')->assertOk()->assertSee(route('packages.index'), false);
    }
}
