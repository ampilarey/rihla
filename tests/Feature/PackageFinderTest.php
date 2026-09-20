<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use App\Support\Money;
use App\Support\PackageFinderOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The package finder.
 *
 * Every option it offers comes from departures that exist. A dropdown
 * offering December when nothing departs in December wastes the one
 * interaction a visitor gives you, and a budget band nobody's prices fall
 * into reads as "nothing here for me".
 */
class PackageFinderTest extends TestCase
{
    use RefreshDatabase;

    private function package(string $title, string $startsAt, int $quadMajor, int $nights = 10): Package
    {
        $package = Package::factory()->create([
            'title' => ['en' => $title],
            'nights' => $nights,
        ]);

        $departure = Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => $startsAt,
            'date_end' => Carbon::parse($startsAt)->addDays($nights),
        ]);

        PriceTier::create([
            'departure_id' => $departure->id,
            'occupancy' => 'quad',
            'amount_minor' => Money::ofMajor($quadMajor)->minor,
        ]);

        return $package;
    }

    // ── The options offered ──────────────────────────────────────────────

    public function test_only_months_something_departs_in_are_offered(): void
    {
        $this->package('October trip', now()->addMonth()->startOfMonth()->addDays(5)->toDateString(), 22_000);

        $options = PackageFinderOptions::build();

        $months = $options->months->pluck('value');

        $this->assertContains(now()->addMonth()->format('Y-m'), $months);
        $this->assertNotContains(now()->addMonths(6)->format('Y-m'), $months);
    }

    public function test_a_past_departure_contributes_no_month(): void
    {
        $package = Package::factory()->create();
        Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->subMonths(2),
            'date_end' => now()->subMonths(2)->addDays(10),
        ]);

        $this->assertTrue(PackageFinderOptions::build()->isEmpty());
    }

    /**
     * With one priced departure there is no band to draw — "up to the only
     * price there is" filters nothing and reads as noise.
     */
    public function test_budget_bands_need_more_than_one_price(): void
    {
        $this->package('Only one', now()->addMonth()->toDateString(), 22_000);

        $this->assertTrue(PackageFinderOptions::build()->budgets->isEmpty());
    }

    public function test_budget_bands_span_the_real_prices(): void
    {
        $this->package('Cheap', now()->addMonth()->toDateString(), 20_000);
        $this->package('Dear', now()->addMonths(2)->toDateString(), 50_000);

        $budgets = PackageFinderOptions::build()->budgets;

        $this->assertNotEmpty($budgets);

        // Rounded to something a person would say out loud, and never below
        // the cheapest package — a band that matches nothing is worse than
        // no band.
        foreach ($budgets as $budget) {
            $this->assertSame(0, $budget['value'] % 1000, 'Budget bands should be round numbers.');
            $this->assertGreaterThanOrEqual(20_000, $budget['value']);
        }

        $this->assertLessThanOrEqual(50_000, $budgets->max('value'));
    }

    // ── Filtering ────────────────────────────────────────────────────────

    public function test_filtering_by_month_narrows_the_list(): void
    {
        // Titles that cannot collide with a month name. The first version of
        // this test called them "October" and "December" and failed on the
        // dropdown, which correctly offers December as a month to choose —
        // the filter was right and the assertion was too blunt.
        $this->package('Alpha package', now()->addMonth()->startOfMonth()->addDays(3)->toDateString(), 22_000);
        $this->package('Beta package', now()->addMonths(3)->startOfMonth()->addDays(3)->toDateString(), 30_000);

        $this->get('/en/packages?month='.now()->addMonth()->format('Y-m'))
            ->assertOk()
            ->assertSee('Alpha package')
            ->assertDontSee('Beta package');
    }

    public function test_filtering_by_budget_excludes_dearer_packages(): void
    {
        $this->package('Affordable', now()->addMonth()->toDateString(), 20_000);
        $this->package('Premium', now()->addMonth()->toDateString(), 60_000);

        $this->get('/en/packages?budget=25000')
            ->assertOk()
            ->assertSee('Affordable')
            ->assertDontSee('Premium');
    }

    public function test_filtering_by_length_excludes_longer_packages(): void
    {
        $this->package('Short', now()->addMonth()->toDateString(), 20_000, nights: 7);
        $this->package('Long', now()->addMonth()->toDateString(), 30_000, nights: 21);

        $this->get('/en/packages?nights=10')
            ->assertOk()
            ->assertSee('Short')
            ->assertDontSee('Long');
    }

    /**
     * A hand-edited or stale URL shows packages, not a validation page. The
     * visitor did not type this and cannot fix it.
     */
    public function test_nonsense_in_the_query_string_is_ignored(): void
    {
        $this->package('Visible', now()->addMonth()->toDateString(), 22_000);

        foreach (['month=1999-99', 'month=banana', 'budget=abc', 'budget=-5', 'nights=0'] as $query) {
            $this->get('/en/packages?'.$query)
                ->assertOk()
                ->assertSee('Visible');
        }
    }

    public function test_a_search_matching_nothing_says_so_and_offers_a_way_back(): void
    {
        $this->package('Expensive', now()->addMonth()->toDateString(), 90_000);

        $this->get('/en/packages?budget=1000')
            ->assertOk()
            ->assertSee('Nothing matches that search')
            ->assertSee('Show all packages');
    }

    /** Filtering is a URL: shareable, bookmarkable, crawlable, no JavaScript. */
    public function test_the_finder_submits_as_a_get_form(): void
    {
        $this->package('Anything', now()->addMonth()->toDateString(), 22_000);

        $this->get('/en/packages')
            ->assertOk()
            ->assertSee('method="GET"', false)
            ->assertSee('name="month"', false);
    }

    public function test_no_finder_is_shown_when_there_is_nothing_to_find(): void
    {
        $this->get('/en/packages')
            ->assertOk()
            ->assertDontSee('Departing in');
    }
}
