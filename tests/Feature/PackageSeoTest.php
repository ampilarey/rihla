<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\Package;
use App\Models\Person;
use App\Models\PriceTier;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structured data and the sitemap for packages.
 *
 * Everything published here is derived from what the page shows. A
 * structured-data property that does not match the visible page is a Google
 * policy violation, and on a licensed travel operator's site an invented
 * credential or price is worse than a missing rich result.
 */
class PackageSeoTest extends TestCase
{
    use RefreshDatabase;

    private function package(int $quadMajor = 22_000, array $departure = []): Package
    {
        $package = Package::factory()->create([
            'title' => ['en' => 'Shawwal Umrah'],
            'summary' => ['en' => 'Ten nights across the two holy cities.'],
        ]);

        $created = Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->addDays(40),
            'date_end' => now()->addDays(50),
            ...$departure,
        ]);

        PriceTier::create([
            'departure_id' => $created->id,
            'occupancy' => 'quad',
            'amount_minor' => Money::ofMajor($quadMajor)->minor,
        ]);

        return $package->refresh();
    }

    private function schema(Package $package): array
    {
        $html = $this->get('/en/packages/'.$package->slug)->assertOk()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', (string) $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);

            if (($decoded['@type'] ?? null) === 'TouristTrip') {
                return $decoded;
            }
        }

        return [];
    }

    public function test_a_package_publishes_itself_as_a_tourist_trip(): void
    {
        $schema = $this->schema($this->package());

        $this->assertSame('TouristTrip', $schema['@type']);
        $this->assertSame('Shawwal Umrah', $schema['name']);
        $this->assertSame('Ten nights across the two holy cities.', $schema['description']);
        $this->assertSame('C11452023', $schema['provider']['identifier']);
    }

    /**
     * The price column holds minor units. Publishing it raw would advertise
     * a hundredfold price to every crawler that reads the page — and unlike
     * a visible price, nobody would notice.
     */
    public function test_the_offer_price_is_in_whole_rufiyaa_not_laari(): void
    {
        $schema = $this->schema($this->package(quadMajor: 22_000));

        $this->assertSame('22000', $schema['offers'][0]['price']);
        $this->assertSame('MVR', $schema['offers'][0]['priceCurrency']);
    }

    public function test_a_sold_out_departure_says_so(): void
    {
        $schema = $this->schema($this->package(departure: [
            'capacity_total' => 24,
            'capacity_confirmed' => 24,
        ]));

        $this->assertSame('https://schema.org/SoldOut', $schema['offers'][0]['availability']);
    }

    /** A departure that has left carries no offer, not an unavailable one. */
    public function test_a_past_departure_publishes_no_offer(): void
    {
        $package = Package::factory()->create(['title' => ['en' => 'Last season']]);
        $departure = Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->subMonth(),
            'date_end' => now()->subMonth()->addDays(10),
        ]);
        PriceTier::create([
            'departure_id' => $departure->id,
            'occupancy' => 'quad',
            'amount_minor' => Money::ofMajor(22_000)->minor,
        ]);

        $this->assertArrayNotHasKey('offers', $this->schema($package->refresh()));
    }

    /**
     * There is no review system, so there is nothing to aggregate. A rating
     * in the markup that the page does not show is exactly the policy
     * violation this helper exists to avoid.
     */
    public function test_no_rating_is_invented(): void
    {
        $schema = $this->schema($this->package());

        $this->assertArrayNotHasKey('aggregateRating', $schema);
        $this->assertArrayNotHasKey('review', $schema);
    }

    // ── The sitemap ──────────────────────────────────────────────────────

    public function test_packages_and_people_are_in_the_sitemap(): void
    {
        $package = $this->package();
        Person::factory()->create();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach (['/en/packages', '/dv/packages', '/en/people', '/en/packages/'.$package->slug] as $path) {
            $this->assertStringContainsString($path, (string) $xml, "{$path} is missing from the sitemap.");
        }
    }

    public function test_the_trips_urls_are_still_in_the_sitemap(): void
    {
        // Additive: /trips is untouched, and dropping it from the sitemap
        // would tell crawlers a live page had gone.
        $this->assertStringContainsString('/en/trips', (string) $this->get('/sitemap.xml')->getContent());
    }

    /**
     * The sitemap is cached for an hour, so publishing a package would
     * otherwise leave it unlisted for up to an hour after it went live —
     * the same gap Trip already closes.
     */
    public function test_publishing_a_package_refreshes_the_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertOk();

        $package = Package::factory()->create(['title' => ['en' => 'Brand new']]);

        $this->assertStringContainsString(
            '/en/packages/'.$package->slug,
            (string) $this->get('/sitemap.xml')->getContent(),
        );
    }
}
