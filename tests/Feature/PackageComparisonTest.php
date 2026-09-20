<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\Package;
use App\Models\PriceTier;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two or three departures side by side.
 *
 * Every operator in this market sends a PDF per package, so a reader
 * comparing two is flipping between documents and holding the differences in
 * their head. The plan rates a diff table highest for impact per effort.
 */
class PackageComparisonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Literal paths, not route(). URL::defaults(['locale' => …]) is set by
     * SetLocale during a request, so route('packages.compare') outside one
     * throws for a missing locale — and a literal path tests the URL a
     * visitor actually shares.
     *
     * @param  array<int, int>|string  $departures
     */
    private function compareUrl(array|string $departures): string
    {
        $query = is_array($departures)
            ? implode('&', array_map(fn (int $id): string => 'departures[]='.$id, $departures))
            : 'departures='.$departures;

        return '/en/packages/compare?'.$query;
    }

    private function departure(string $title, int $quadMajor, array $attributes = []): Departure
    {
        $package = Package::factory()->create(['title' => ['en' => $title]]);

        $departure = Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->addDays(40),
            'date_end' => now()->addDays(50),
            ...$attributes,
        ]);

        PriceTier::create([
            'departure_id' => $departure->id,
            'occupancy' => 'quad',
            'amount_minor' => Money::ofMajor($quadMajor)->minor,
        ]);

        return $departure;
    }

    public function test_two_departures_are_shown_side_by_side(): void
    {
        $a = $this->departure('Ramadan Umrah', 28_500, ['airline' => 'Emirates']);
        $b = $this->departure('Shawwal Umrah', 22_000, ['airline' => 'Qatar Airways']);

        $this->get($this->compareUrl([$a->id, $b->id]))
            ->assertOk()
            ->assertSee('Ramadan Umrah')
            ->assertSee('Shawwal Umrah')
            ->assertSee('MVR 28,500')
            ->assertSee('MVR 22,000')
            ->assertSee('Emirates')
            ->assertSee('Qatar Airways');
    }

    /**
     * A shareable URL, and one that works without JavaScript — which is the
     * reason this is a server-rendered GET rather than a client-side table.
     */
    public function test_the_ids_can_arrive_as_a_comma_separated_string(): void
    {
        $a = $this->departure('Ramadan Umrah', 28_500);
        $b = $this->departure('Shawwal Umrah', 22_000);

        $this->get($this->compareUrl($a->id.','.$b->id))
            ->assertOk()
            ->assertSee('Ramadan Umrah')
            ->assertSee('Shawwal Umrah');
    }

    /** Three columns plus a label column is what fits a phone. */
    public function test_no_more_than_three_are_compared(): void
    {
        $departures = collect(range(1, 5))->map(fn (int $i) => $this->departure("Package {$i}", 20_000 + $i));

        $response = $this->get($this->compareUrl($departures->pluck('id')->all()))->assertOk();

        $response->assertSee('Package 1');
        $response->assertSee('Package 3');
        $response->assertDontSee('Package 4');
    }

    public function test_the_visitors_order_is_kept(): void
    {
        $a = $this->departure('Alpha', 20_000);
        $b = $this->departure('Beta', 30_000);

        $html = $this->get($this->compareUrl([$b->id, $a->id]))
            ->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'Alpha'),
            strpos($html, 'Beta'),
            'The table reordered the columns rather than keeping the order chosen.',
        );
    }

    public function test_an_empty_selection_explains_itself(): void
    {
        $this->get('/en/packages/compare')
            ->assertOk()
            ->assertSee('Choose two or three departures');
    }

    public function test_an_unpublished_departure_cannot_be_compared(): void
    {
        $visible = $this->departure('Visible', 20_000);
        $hidden = $this->departure('Hidden', 20_000, ['is_published' => false]);

        $this->get($this->compareUrl([$visible->id, $hidden->id]))
            ->assertOk()
            ->assertSee('Visible')
            ->assertDontSee('Hidden');
    }

    public function test_a_departure_whose_package_is_unpublished_cannot_be_compared(): void
    {
        $visible = $this->departure('Visible', 20_000);
        $hidden = $this->departure('Hidden', 20_000);
        $hidden->package->update(['is_published' => false]);

        $this->get($this->compareUrl([$visible->id, $hidden->id]))
            ->assertOk()
            ->assertDontSee('Hidden');
    }

    /**
     * A comparison link saved last season would otherwise resurrect a
     * departure that has already left and present it as bookable.
     */
    public function test_a_departure_that_has_left_is_dropped(): void
    {
        $upcoming = $this->departure('Next season', 20_000);
        $past = $this->departure('Last season', 20_000, [
            'date_start' => now()->subMonths(2),
            'date_end' => now()->subMonths(2)->addDays(10),
        ]);

        $this->get($this->compareUrl([$upcoming->id, $past->id]))
            ->assertOk()
            ->assertSee('Next season')
            ->assertDontSee('Last season');
    }

    /**
     * A blank cell where one departure does not offer a room the other does
     * is itself worth seeing, so the row is drawn for every occupancy any of
     * them prices.
     */
    public function test_a_room_one_departure_does_not_offer_still_gets_a_row(): void
    {
        $a = $this->departure('Has a single', 28_500);
        PriceTier::create([
            'departure_id' => $a->id,
            'occupancy' => 'single',
            'amount_minor' => Money::ofMajor(52_000)->minor,
        ]);

        $b = $this->departure('Quad only', 22_000);

        $this->get($this->compareUrl([$a->id, $b->id]))
            ->assertOk()
            ->assertSee('Single room')
            ->assertSee('MVR 52,000');
    }

    public function test_hotel_distances_are_compared(): void
    {
        $a = $this->departure('Near', 28_500);
        DepartureHotel::create([
            'departure_id' => $a->id, 'city' => 'makkah',
            'name' => 'Swissotel Al Maqam', 'distance_metres' => 300, 'walk_minutes' => 4,
        ]);

        $b = $this->departure('Far', 22_000);
        DepartureHotel::create([
            'departure_id' => $b->id, 'city' => 'makkah',
            'name' => 'Somewhere Further', 'distance_metres' => 1200,
        ]);

        $this->get($this->compareUrl([$a->id, $b->id]))
            ->assertOk()
            ->assertSee('300 m')
            ->assertSee('1.2 km');
    }

    /** The whole point of the GET form on the listing. */
    public function test_the_listing_offers_a_no_javascript_way_to_choose(): void
    {
        $this->departure('Ramadan Umrah', 28_500);

        $this->get('/en/packages')
            ->assertOk()
            ->assertSee('name="departures[]"', false)
            ->assertSee('/en/packages/compare', false);
    }
}
