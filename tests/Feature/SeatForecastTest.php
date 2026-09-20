<?php

namespace Tests\Feature;

use App\Filament\Pages\Forecast;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Models\User;
use App\Support\Access;
use App\Support\SeatForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seat forecasting — §8.5.
 *
 * The forecast itself is one division. What these tests hold down is
 * **when it declines**, because a projection from thin history is worse
 * than no projection: it is a confident number, and a seat forecast is
 * something somebody charters an aircraft on.
 *
 * Every refusal has a test that plants its absence, and the range
 * direction has one of its own — a front-loaded past journey projects this
 * one *low*, and getting that backwards inverts every recommendation on
 * the screen while leaving it looking entirely reasonable.
 */
class SeatForecastTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->package = Package::factory()->create();
    }

    private function departure(int $daysFromNow, int $capacity = 40): Departure
    {
        return Departure::factory()->withSeats($capacity)->create([
            'package_id' => $this->package->getKey(),
            'date_start' => now()->addDays($daysFromNow)->startOfDay(),
            'date_end' => now()->addDays($daysFromNow + 10)->startOfDay(),
        ]);
    }

    private function sell(Departure $departure, int $seats, ?\DateTimeInterface $bookedAt = null): Booking
    {
        return Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => $seats,
            'total_minor' => 1_000_000,
            'created_at' => $bookedAt ?? now(),
        ]);
    }

    /**
     * A past journey that sold `$early` seats by `$leadDays` before it
     * flew, and `$final` in total.
     */
    private function history(int $flewDaysAgo, int $final, int $early, int $leadDays, int $capacity = 40): Departure
    {
        $departure = Departure::factory()->withSeats($capacity)->create([
            'package_id' => $this->package->getKey(),
            'date_start' => now()->subDays($flewDaysAgo)->startOfDay(),
            'date_end' => now()->subDays($flewDaysAgo - 10)->startOfDay(),
        ]);

        $this->sell($departure, $early, now()->subDays($flewDaysAgo + $leadDays));

        if ($final > $early) {
            $this->sell($departure, $final - $early, now()->subDays($flewDaysAgo + 1));
        }

        return $departure;
    }

    // ── The refusals ─────────────────────────────────────────────────────

    public function test_thin_history_refuses_rather_than_extrapolating(): void
    {
        $this->history(flewDaysAgo: 100, final: 40, early: 20, leadDays: 60);
        $this->history(flewDaysAgo: 160, final: 40, early: 20, leadDays: 60);

        $selling = $this->departure(60);
        $this->sell($selling, 20);

        $forecast = SeatForecast::build($selling);

        $this->assertFalse($forecast->hasProjection());
        $this->assertNull($forecast->low);
        $this->assertStringContainsString('at least 3', (string) $forecast->because);
        $this->assertStringContainsString('confident face', (string) $forecast->because);
    }

    public function test_a_departure_with_nothing_sold_gets_no_projection(): void
    {
        foreach ([100, 160, 220] as $ago) {
            $this->history(flewDaysAgo: $ago, final: 40, early: 20, leadDays: 60);
        }

        $forecast = SeatForecast::build($this->departure(60));

        $this->assertFalse($forecast->hasProjection());
        $this->assertStringContainsString('Pace needs something to pace', (string) $forecast->because);
    }

    /** Close in, the question changes, and another screen already answers it. */
    public function test_a_departure_about_to_leave_is_left_to_the_departure_board(): void
    {
        foreach ([100, 160, 220] as $ago) {
            $this->history(flewDaysAgo: $ago, final: 40, early: 20, leadDays: 3);
        }

        $imminent = $this->departure(3);
        $this->sell($imminent, 30);

        $forecast = SeatForecast::build($imminent);

        $this->assertFalse($forecast->hasProjection());
        $this->assertStringContainsString('departure board', (string) $forecast->because);
    }

    /**
     * Nobody having booked this early on any past journey is itself the
     * answer, and it is not the same as thin history.
     */
    public function test_no_history_this_far_out_says_so_rather_than_dividing_by_zero(): void
    {
        // All three sold everything in the last fortnight before flying.
        foreach ([100, 160, 220] as $ago) {
            $this->history(flewDaysAgo: $ago, final: 40, early: 40, leadDays: 5);
        }

        $selling = $this->departure(120);
        $this->sell($selling, 4);

        $forecast = SeatForecast::build($selling);

        $this->assertFalse($forecast->hasProjection());
        $this->assertStringContainsString('no pace to divide by', (string) $forecast->because);
        $this->assertStringContainsString('earlier than this operator', (string) $forecast->because);
    }

    public function test_a_departure_with_no_capacity_has_nothing_to_fill(): void
    {
        $departure = Departure::factory()->create([
            'package_id' => $this->package->getKey(),
            'date_start' => now()->addDays(60)->startOfDay(),
            'date_end' => now()->addDays(70)->startOfDay(),
            // All three, or the overbooking constraint fires on the
            // factory's own random confirmed count.
            'capacity_total' => 0,
            'capacity_held' => 0,
            'capacity_confirmed' => 0,
        ]);

        $this->assertStringContainsString(
            'No capacity has been set',
            (string) SeatForecast::build($departure)->because,
        );
    }

    public function test_a_departure_that_has_flown_is_not_forecast(): void
    {
        $flown = Departure::factory()->withSeats(40)->create([
            'package_id' => $this->package->getKey(),
            'date_start' => now()->subDays(10)->startOfDay(),
            'date_end' => now()->startOfDay(),
        ]);

        $this->assertStringContainsString(
            'already flown',
            (string) SeatForecast::build($flown)->because,
        );
    }

    // ── The projection ───────────────────────────────────────────────────

    /**
     * Three journeys that had each sold half their seats 60 days out, and
     * one selling now with 20 sold at 60 days: it projects at 40.
     */
    public function test_a_consistent_history_projects_a_tight_range(): void
    {
        foreach ([100, 160, 220] as $ago) {
            $this->history(flewDaysAgo: $ago, final: 40, early: 20, leadDays: 60);
        }

        $selling = $this->departure(60);
        $this->sell($selling, 20);

        $forecast = SeatForecast::build($selling);

        $this->assertTrue($forecast->hasProjection());
        $this->assertSame(40, $forecast->low);
        $this->assertSame(40, $forecast->high);
        $this->assertSame(3, $forecast->comparableJourneys);
        $this->assertTrue($forecast->comparablesSharePackage);
    }

    /**
     * The direction that inverts every recommendation if it is wrong.
     *
     * A past journey that had already sold most of its seats this far out
     * front-loaded. This one, at the same seat count, is therefore tracking
     * toward a *smaller* final number, not a larger one. Getting it
     * backwards leaves a screen that looks perfectly reasonable and advises
     * the opposite of the truth on every row.
     */
    public function test_a_front_loaded_history_pulls_the_bottom_of_the_range_down(): void
    {
        // One sold 80% by now, one 50%, one 25%.
        $this->history(flewDaysAgo: 100, final: 40, early: 32, leadDays: 60);
        $this->history(flewDaysAgo: 160, final: 40, early: 20, leadDays: 60);
        $this->history(flewDaysAgo: 220, final: 40, early: 10, leadDays: 60);

        $selling = $this->departure(60);
        $this->sell($selling, 20);

        $forecast = SeatForecast::build($selling);

        // 20 / 0.80 = 25 at the bottom; 20 / 0.25 = 80 at the top.
        $this->assertSame(25, $forecast->low);
        $this->assertSame(80, $forecast->high);
    }

    public function test_a_projection_that_clears_capacity_says_to_open_the_waiting_list(): void
    {
        foreach ([100, 160, 220] as $ago) {
            $this->history(flewDaysAgo: $ago, final: 40, early: 10, leadDays: 60);
        }

        $selling = $this->departure(60, capacity: 30);
        $this->sell($selling, 20);

        $forecast = SeatForecast::build($selling);

        $this->assertSame('success', $forecast->tone());
        $this->assertStringContainsString('Open the waiting list', (string) $forecast->advice());
    }

    public function test_a_projection_that_falls_short_names_the_empty_seats(): void
    {
        foreach ([100, 160, 220] as $ago) {
            $this->history(flewDaysAgo: $ago, final: 40, early: 20, leadDays: 60);
        }

        $selling = $this->departure(60, capacity: 60);
        $this->sell($selling, 10);

        $forecast = SeatForecast::build($selling);

        $this->assertSame('danger', $forecast->tone());
        $this->assertSame(20, $forecast->high);
        $this->assertStringContainsString('40 seats unsold', (string) $forecast->advice());
    }

    /** The sentence carries the history, because "42 seats" invites a decision. */
    public function test_the_projection_is_spoken_with_the_history_behind_it(): void
    {
        foreach ([100, 160, 220] as $ago) {
            $this->history(flewDaysAgo: $ago, final: 40, early: 20, leadDays: 60);
        }

        $selling = $this->departure(60);
        $this->sell($selling, 20);

        $spoken = (string) SeatForecast::build($selling)->spoken();

        $this->assertStringContainsString('3 past journeys', $spoken);
        $this->assertStringContainsString('this same package', $spoken);
        $this->assertStringContainsString('capacity of 40', $spoken);
    }

    /** Cancelled seats are not sold seats, here as everywhere else. */
    public function test_a_cancelled_booking_is_not_a_sold_seat(): void
    {
        foreach ([100, 160, 220] as $ago) {
            $this->history(flewDaysAgo: $ago, final: 40, early: 20, leadDays: 60);
        }

        $selling = $this->departure(60);
        $this->sell($selling, 20);

        Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $selling->getKey(),
            'status' => Booking::CANCELLED,
            'seats' => 10,
            'created_at' => now(),
        ]);

        $this->assertSame(20, SeatForecast::build($selling)->soldNow);
    }

    /** Too few of this package, so it widens — and says which it used. */
    public function test_it_falls_back_to_other_packages_and_names_the_fallback(): void
    {
        $other = Package::factory()->create();

        foreach ([100, 160, 220] as $ago) {
            $departure = Departure::factory()->withSeats(40)->create([
                'package_id' => $other->getKey(),
                'date_start' => now()->subDays($ago)->startOfDay(),
                'date_end' => now()->subDays($ago - 10)->startOfDay(),
            ]);

            $this->sell($departure, 20, now()->subDays($ago + 60));
            $this->sell($departure, 20, now()->subDays($ago + 1));
        }

        $selling = $this->departure(60);
        $this->sell($selling, 20);

        $forecast = SeatForecast::build($selling);

        $this->assertTrue($forecast->hasProjection());
        $this->assertFalse($forecast->comparablesSharePackage);
        $this->assertStringContainsString('across all packages', (string) $forecast->spoken());
    }

    // ── The screen ───────────────────────────────────────────────────────

    public function test_the_dashboard_permission_opens_the_forecast_too(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Access::REPORTING))
            ->get(Forecast::getUrl())
            ->assertSuccessful();
    }

    public function test_a_role_without_it_cannot_open_the_forecast(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Access::TOUR_LEADER))
            ->get(Forecast::getUrl())
            ->assertForbidden();
    }

    /**
     * A departure with no projection still gets a row saying why.
     *
     * Omitting it would read as a departure nobody has looked at, which is
     * the opposite of what it means.
     */
    public function test_a_departure_without_a_projection_is_still_listed_with_its_reason(): void
    {
        $selling = $this->departure(60);
        $this->sell($selling, 5);

        $this->actingAs(User::factory()->create()->assignRole(Access::REPORTING))
            ->get(Forecast::getUrl())
            ->assertSuccessful()
            ->assertSee('No projection for this one')
            ->assertSee('at least 3');
    }
}
