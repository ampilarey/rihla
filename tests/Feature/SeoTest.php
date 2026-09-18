<?php

namespace Tests\Feature;

use App\Models\GuideStep;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'Seven Nights in Madinah',
            'slug' => 'seven-nights-madinah',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-08',
            'location' => 'Madinah',
            'summary' => 'A guided Umrah departure.',
            'price_from_mvr' => 38000,
            'status' => 'upcoming',
            'is_published' => true,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function schemaOfType(string $html, string $type): array
    {
        preg_match_all(
            '#<script type="application/ld\+json">(.*?)</script>#s',
            $html,
            $matches,
        );

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);

            $this->assertIsArray($decoded, "Emitted JSON-LD is not valid JSON: {$json}");

            if (($decoded['@type'] ?? null) === $type) {
                return $decoded;
            }
        }

        $this->fail("No JSON-LD block of type {$type} was emitted.");
    }

    public function test_every_public_page_declares_its_alternates(): void
    {
        $html = $this->get('/en/guide')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="en" href="'.url('/en/guide').'">',
            $html,
        );
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="dv" href="'.url('/dv/guide').'">',
            $html,
        );
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="x-default" href="'.url('/en/guide').'">',
            $html,
        );
    }

    public function test_the_canonical_url_differs_per_locale(): void
    {
        $this->get('/en/guide')
            ->assertSee('<link rel="canonical" href="'.url('/en/guide').'">', false);

        $this->get('/dv/guide')
            ->assertSee('<link rel="canonical" href="'.url('/dv/guide').'">', false);
    }

    /**
     * An unprefixed page has no translation, so claiming one would point
     * crawlers at a URL that does not exist.
     */
    public function test_unprefixed_pages_declare_no_alternates(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('rel="alternate" hreflang', false);
    }

    public function test_the_organization_is_described_on_every_page(): void
    {
        $html = $this->get('/en/guide')->assertOk()->getContent();

        $organization = $this->schemaOfType($html, 'TravelAgency');

        $this->assertSame('Rihla Travels', $organization['name']);
        $this->assertSame('C11452023', $organization['identifier']);
        $this->assertSame('MV', $organization['address']['addressCountry']);
    }

    public function test_a_trip_page_describes_the_trip(): void
    {
        $this->trip();

        $html = $this->get('/en/trips/seven-nights-madinah')->assertOk()->getContent();

        $trip = $this->schemaOfType($html, 'TouristTrip');

        $this->assertSame('Seven Nights in Madinah', $trip['name']);
        // ISO 8601, as the acceptance criteria require.
        $this->assertSame('2026-03-01', $trip['startDate']);
        $this->assertSame('2026-03-08', $trip['endDate']);
        $this->assertSame('AggregateOffer', $trip['offers']['@type']);
        $this->assertSame('38000', $trip['offers']['lowPrice']);
        $this->assertSame('MVR', $trip['offers']['priceCurrency']);
    }

    /**
     * `price_from_mvr` is a "from" figure. Emitted as an exact Offer price it
     * would put a number in search results that no customer can book at.
     */
    public function test_a_from_price_is_never_emitted_as_an_exact_price(): void
    {
        $this->trip();

        $html = $this->get('/en/trips/seven-nights-madinah')->assertOk()->getContent();
        $trip = $this->schemaOfType($html, 'TouristTrip');

        $this->assertArrayNotHasKey('price', $trip['offers']);
    }

    /**
     * The site holds no reviews. A rating in structured data that no visitor
     * can see on the page is a Google policy violation and a lie to customers.
     */
    public function test_no_page_claims_a_rating(): void
    {
        $this->trip();

        $this->get('/en/trips/seven-nights-madinah')
            ->assertOk()
            ->assertDontSee('AggregateRating', false)
            ->assertDontSee('ratingValue', false);
    }

    public function test_a_past_trip_carries_no_offer(): void
    {
        $this->trip(['status' => 'past']);

        $html = $this->get('/en/trips/seven-nights-madinah')->assertOk()->getContent();
        $trip = $this->schemaOfType($html, 'TouristTrip');

        $this->assertArrayNotHasKey('offers', $trip);
    }

    public function test_a_trip_page_carries_a_breadcrumb_trail(): void
    {
        $this->trip();

        $html = $this->get('/en/trips/seven-nights-madinah')->assertOk()->getContent();
        $crumbs = $this->schemaOfType($html, 'BreadcrumbList');

        $this->assertCount(3, $crumbs['itemListElement']);
        $this->assertSame(url('/en'), $crumbs['itemListElement'][0]['item']);
        $this->assertSame(url('/en/trips'), $crumbs['itemListElement'][1]['item']);
        // The final crumb is the current page and carries no link, which is
        // what tells a crawler where the trail ends.
        $this->assertArrayNotHasKey('item', $crumbs['itemListElement'][2]);
        $this->assertSame('Seven Nights in Madinah', $crumbs['itemListElement'][2]['name']);
    }

    public function test_the_guide_is_described_as_ordered_steps(): void
    {
        GuideStep::create([
            'step_number' => 1,
            'locale' => 'en',
            'title' => 'Ihram',
            'summary' => 'Enter the state of Ihram at the miqat.',
            'is_published' => true,
        ]);

        $html = $this->get('/en/guide')->assertOk()->getContent();
        $guide = $this->schemaOfType($html, 'HowTo');

        $this->assertSame('Ihram', $guide['step'][0]['name']);
        $this->assertSame(1, $guide['step'][0]['position']);
        $this->assertSame('Enter the state of Ihram at the miqat.', $guide['step'][0]['text']);
    }

    /**
     * Titles and summaries come from the admin panel. Without escaping, a
     * "</script>" in one would break out of the JSON-LD block and inject
     * whatever followed into the page.
     */
    public function test_content_cannot_break_out_of_a_schema_block(): void
    {
        $this->trip(['title' => 'Madinah </script><script>alert(1)</script>']);

        $this->get('/en/trips/seven-nights-madinah')
            ->assertOk()
            ->assertDontSee('</script><script>alert(1)', false);
    }

    public function test_the_sitemap_lists_both_locales(): void
    {
        $this->trip();

        $response = $this->get('/sitemap.xml')->assertOk();

        $response->assertHeader('Content-Type', 'application/xml');

        $xml = $response->getContent();

        foreach (['/en', '/dv', '/en/trips', '/dv/trips', '/en/guide', '/dv/guide'] as $path) {
            $this->assertStringContainsString('<loc>'.url($path).'</loc>', $xml);
        }

        $this->assertStringContainsString(
            '<loc>'.url('/en/trips/seven-nights-madinah').'</loc>',
            $xml,
        );
        $this->assertStringContainsString(
            '<loc>'.url('/dv/trips/seven-nights-madinah').'</loc>',
            $xml,
        );
    }

    public function test_the_sitemap_is_well_formed(): void
    {
        $this->trip();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($document, 'The sitemap is not well-formed XML.');
    }

    public function test_the_sitemap_omits_unpublished_trips(): void
    {
        $this->trip(['is_published' => false]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('seven-nights-madinah');
    }

    /**
     * The sitemap is cached for an hour, so without invalidation a newly
     * published trip would be missing from it for up to an hour after it
     * went live.
     */
    public function test_publishing_a_trip_refreshes_the_cached_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('seven-nights-madinah');

        $this->trip();

        $this->get('/sitemap.xml')->assertOk()->assertSee('seven-nights-madinah');
    }

    public function test_robots_points_at_the_sitemap_and_excludes_private_areas(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Sitemap: https://rihla.mv/sitemap.xml', $robots);
        $this->assertStringContainsString('Disallow: /admin', $robots);
    }
}
