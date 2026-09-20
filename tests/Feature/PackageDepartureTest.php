<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\ItineraryItem;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\Trip;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The product/date split: a Package is what Rihla sells, a Departure is one
 * dated run of it.
 *
 * `trips` made the two the same row, so running the same package twice meant
 * retyping it and getting a second URL, a second SEO history and copy that
 * drifts. Everything Phase 2 sells on needs them separate.
 */
class PackageDepartureTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../database/migrations/2026_09_20_100000_create_packages_and_departures.php';

    /**
     * The booking tables hold foreign keys into `packages`, `departures` and
     * `price_tiers`, so they have to come off before those tables can be
     * dropped and go back on after. MySQL refuses the drop otherwise —
     * "Cannot drop table 'price_tiers' referenced by a foreign key
     * constraint" — while SQLite allows it and leaves the references
     * dangling, so this ordering is invisible until CI runs against the
     * engine production uses.
     *
     * @var list<string>
     */
    private const DEPENDENT_MIGRATIONS = [
        __DIR__.'/../../database/migrations/2026_09_20_141000_add_the_departure_capacity_constraint.php',
        __DIR__.'/../../database/migrations/2026_09_20_140000_create_booking_domain.php',
    ];

    // ── The split ────────────────────────────────────────────────────────

    public function test_one_package_carries_many_departures(): void
    {
        $package = Package::factory()->create();

        Departure::factory()->count(3)->create(['package_id' => $package->id]);

        $this->assertCount(3, $package->refresh()->departures);
        $this->assertSame($package->id, $package->departures->first()->package->id);
    }

    public function test_a_package_is_translatable_per_field(): void
    {
        $package = Package::create([
            'slug' => 'ramadan-umrah',
            'title' => ['en' => 'Ramadan Umrah', 'dv' => 'ރަމަޟާން ޢުމްރާ'],
            'summary' => ['en' => 'Fourteen nights across the two holy cities.'],
        ]);

        $this->assertSame('ރަމަޟާން ޢުމްރާ', $package->getTranslation('title', 'dv'));
        // No Dhivehi summary, so it falls back rather than rendering blank.
        $this->assertSame('Fourteen nights across the two holy cities.', $package->getTranslation('summary', 'dv'));
    }

    public function test_inclusions_are_a_list_per_language(): void
    {
        $package = Package::factory()->create([
            'inclusions' => ['en' => ['Return flights', 'Visa processing']],
        ]);

        $this->assertSame(['Return flights', 'Visa processing'], $package->refresh()->inclusion_list);
        // An absent list is an empty list, never null — the views iterate it.
        $this->assertSame([], Package::factory()->create(['exclusions' => null])->exclusion_list);
    }

    // ── Money, in minor units ([R-7]) ────────────────────────────────────

    public function test_prices_are_stored_as_integer_minor_units(): void
    {
        $departure = Departure::factory()->create();

        $tier = PriceTier::create([
            'departure_id' => $departure->id,
            'occupancy' => 'quad',
            'amount_minor' => Money::ofMajor(28_500)->minor,
        ]);

        // 28,500 rufiyaa is 2,850,000 laari. If this ever reads 28500 the
        // column has been filled with whole units and every price on the site
        // is out by a factor of a hundred.
        $this->assertSame(2_850_000, DB::table('price_tiers')->where('id', $tier->id)->value('amount_minor'));
        $this->assertSame('MVR 28,500', $tier->formatted);
    }

    public function test_the_column_holds_an_integer_not_a_float(): void
    {
        $type = collect(Schema::getColumns('price_tiers'))->firstWhere('name', 'amount_minor');

        $this->assertStringNotContainsStringIgnoringCase('decimal', (string) $type['type']);
        $this->assertStringNotContainsStringIgnoringCase('float', (string) $type['type']);
        $this->assertStringNotContainsStringIgnoringCase('double', (string) $type['type']);
    }

    public function test_a_price_with_laari_still_formats(): void
    {
        $this->assertSame('MVR 28,500.50', Money::ofMinor(2_850_050)->format());
        $this->assertSame('USD 1,200', Money::ofMinor(120_000, 'USD')->format());
    }

    /**
     * Money formatting must not need the intl extension.
     *
     * The first draft used NumberFormatter and passed here, where intl
     * happens to be installed. It is not on the CI runners, and nobody has
     * confirmed it on the cPanel account — so it would have fataled on every
     * page showing a price, on a host this application cannot inspect.
     */
    public function test_formatting_a_price_does_not_need_the_intl_extension(): void
    {
        // Comments stripped first: the class explains at the top why it does
        // not use NumberFormatter, and naming it there must not fail this.
        $code = implode('', array_map(
            fn (array|string $token): string => is_string($token) ? $token : (
                in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]
            ),
            token_get_all((string) file_get_contents(app_path('Support/Money.php'))),
        ));

        $this->assertStringNotContainsString(
            'NumberFormatter',
            $code,
            'Money must format with core PHP; intl is not installed in CI or confirmed on production.',
        );
    }

    public function test_the_lead_price_is_the_cheapest_tier(): void
    {
        $departure = Departure::factory()->create();

        foreach ([['single', 52_000], ['double', 36_000], ['quad', 28_500]] as [$occupancy, $major]) {
            PriceTier::create([
                'departure_id' => $departure->id,
                'occupancy' => $occupancy,
                'amount_minor' => Money::ofMajor($major)->minor,
            ]);
        }

        $this->assertSame('MVR 28,500', $departure->refresh()->lead_price?->format());
    }

    public function test_one_price_per_occupancy_and_traveller_type(): void
    {
        $departure = Departure::factory()->create();

        PriceTier::create(['departure_id' => $departure->id, 'occupancy' => 'quad', 'amount_minor' => 100]);

        $this->expectException(QueryException::class);

        PriceTier::create(['departure_id' => $departure->id, 'occupancy' => 'quad', 'amount_minor' => 200]);
    }

    // ── Seats ────────────────────────────────────────────────────────────

    public function test_seats_count_held_and_confirmed_separately(): void
    {
        $departure = Departure::factory()->create([
            'capacity_total' => 24,
            'capacity_held' => 2,
            'capacity_confirmed' => 16,
        ]);

        // A held seat is neither free nor sold. Phase 3's seat holds move it;
        // the arithmetic is here now so the bar need not be rewritten.
        $this->assertSame(18, $departure->seats_taken);
        $this->assertSame(6, $departure->seats_remaining);
        $this->assertSame(75, $departure->percent_sold);
        $this->assertFalse($departure->is_sold_out);
    }

    /**
     * `make()`, not `create()`: since Phase 3 the database refuses to store
     * an oversold row at all (see SeatAllocationTest). This is still worth
     * asserting, because the display guard has to hold for a row written
     * before the constraint existed — and on a MySQL too old to enforce it,
     * which [R-5] leaves open until somebody reads the production version.
     */
    public function test_an_oversold_departure_never_shows_negative_seats(): void
    {
        $departure = Departure::factory()->make([
            'capacity_total' => 24,
            'capacity_confirmed' => 27,
        ]);

        // Overselling is a real problem, but "-3 seats left" in front of a
        // customer is a worse one.
        $this->assertSame(0, $departure->seats_remaining);
        $this->assertSame(100, $departure->percent_sold);
        $this->assertTrue($departure->is_sold_out);
    }

    public function test_a_departure_with_no_capacity_recorded_draws_no_bar(): void
    {
        $departure = Departure::factory()->withoutCapacity()->create();

        // Backfilled departures start this way. "0 of 0 seats" and a full bar
        // would both be lies; the view asks has_capacity first.
        $this->assertFalse($departure->has_capacity);
        $this->assertFalse($departure->is_sold_out);
        $this->assertSame(0, $departure->percent_sold);
    }

    // ── Hotels and itinerary ─────────────────────────────────────────────

    public function test_hotel_distance_reads_in_metres_then_kilometres(): void
    {
        $departure = Departure::factory()->create();

        $near = DepartureHotel::create([
            'departure_id' => $departure->id, 'city' => 'makkah',
            'name' => 'Swissotel Al Maqam', 'distance_metres' => 300, 'walk_minutes' => 4,
        ]);

        $far = DepartureHotel::create([
            'departure_id' => $departure->id, 'city' => 'madinah',
            'name' => 'Somewhere Further', 'distance_metres' => 1200,
        ]);

        $this->assertSame('300 m', $near->distance_label);
        $this->assertSame('1.2 km', $far->distance_label);
        $this->assertNull(DepartureHotel::create([
            'departure_id' => $departure->id, 'city' => 'makkah', 'name' => 'Unknown distance',
        ])->distance_label);
    }

    public function test_the_itinerary_is_ordered_by_day_and_translatable(): void
    {
        $departure = Departure::factory()->create();

        foreach ([3, 1, 2] as $day) {
            ItineraryItem::create([
                'departure_id' => $departure->id,
                'day_number' => $day,
                'title' => ['en' => "Day {$day}"],
            ]);
        }

        $this->assertSame([1, 2, 3], $departure->refresh()->itinerary->pluck('day_number')->all());
    }

    // ── The backfill ─────────────────────────────────────────────────────

    /**
     * Every trip becomes one package and one departure, and `trips` is left
     * exactly as it was. Rolling this migration back loses nothing.
     */
    public function test_each_trip_is_copied_into_a_package_and_a_departure(): void
    {
        foreach (self::DEPENDENT_MIGRATIONS as $migration) {
            $this->artisan('migrate:rollback', ['--path' => $migration, '--realpath' => true])
                ->assertSuccessful();
        }

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => true])
            ->assertSuccessful();

        $trip = Trip::create([
            'title' => ['en' => 'Ramadan Umrah — 14 Nights', 'dv' => 'ރަމަޟާން ޢުމްރާ'],
            'slug' => 'ramadan-umrah-14-nights',
            'date_start' => '2026-09-14',
            'date_end' => '2026-09-28',
            'price_from_mvr' => 28_500,
            'status' => Trip::STATUS_UPCOMING,
            'is_published' => true,
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION, '--realpath' => true])
            ->assertSuccessful();

        foreach (array_reverse(self::DEPENDENT_MIGRATIONS) as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true])
                ->assertSuccessful();
        }

        $departure = Departure::sole();
        $package = $departure->package;

        $this->assertSame('Ramadan Umrah — 14 Nights', $package->getTranslation('title', 'en'));
        $this->assertSame('ރަމަޟާން ޢުމްރާ', $package->getTranslation('title', 'dv'), 'Both languages must survive the copy.');
        $this->assertSame(14, $package->nights);

        // Lineage: which trip this came from, so the copy can be audited and
        // `trips` retired deliberately rather than hopefully.
        $this->assertSame($trip->id, $departure->trip_id);

        // Whole rufiyaa in the old column, laari in the new one.
        $this->assertSame(2_850_000, $departure->priceTiers->sole()->amount_minor);

        // The old row is untouched and still readable.
        $this->assertSame(28_500, $trip->fresh()->price_from_mvr);
        $this->assertDatabaseCount('trips', 1);
    }

    public function test_a_trip_is_never_copied_twice(): void
    {
        $departure = Departure::factory()->create();
        $trip = Trip::create([
            'title' => ['en' => 'A trip'], 'slug' => 'a-trip',
            'date_start' => '2026-01-01', 'date_end' => '2026-01-10', 'status' => 'upcoming',
        ]);

        $departure->update(['trip_id' => $trip->id]);

        $this->expectException(QueryException::class);

        Departure::factory()->create(['trip_id' => $trip->id]);
    }

    public function test_the_trips_table_is_left_alone(): void
    {
        // Additive and reversible: this migration creates tables and copies
        // rows. If it ever starts dropping or renaming a trips column, the
        // old site stops working mid-transition.
        foreach (['id', 'slug', 'title', 'date_start', 'date_end', 'price_from_mvr', 'status'] as $column) {
            $this->assertTrue(Schema::hasColumn('trips', $column), "trips.{$column} was removed.");
        }
    }
}
