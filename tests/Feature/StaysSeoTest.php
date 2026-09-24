<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Property;
use App\Models\RoomType;
use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structured data for the stays pages — §15.7.
 *
 * A guesthouse page was the one public page on this site publishing no
 * machine-readable description of itself at all: the share kit of Phase 9.5
 * made the link unfurl for a person, and left every crawler and every AI
 * search surface reading the prose and guessing.
 *
 * Everything published here is on the page. A structured-data property that
 * does not match what the reader sees is a Google policy violation, and on a
 * licensed travel operator's site an invented credential or price is worse
 * than a missing rich result — which is why the assertions below are as much
 * about what is *absent* as about what is there.
 *
 * Named for the half it covers. `StaysPagesTest` and `StaysPublicPagesTest`
 * both already exist, and AGENTS.md records what happens when a second suite
 * on one domain takes the obvious name.
 */
class StaysSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The strand is off by default, and an off strand 404s the page —
        // correctly, but it would make every assertion below about routing
        // rather than about structured data.
        Services::save(['stays_guesthouses' => Services::ON]);
    }

    private function property(array $attributes = []): Property
    {
        return Property::factory()->create([
            'name' => ['en' => 'Maafushi View'],
            'summary' => ['en' => 'Eight rooms and a house reef, two minutes from the jetty.'],
            'island' => 'Maafushi',
            'currency' => 'USD',
            'amenities' => ['en' => ['Air conditioning', 'Wi-Fi']],
            'check_in_time' => '14:00',
            'check_out_time' => '11:00',
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function schema(Property $property): array
    {
        $html = (string) $this->get('/en/stays/'.$property->slug)->assertOk()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);

            if (($decoded['@type'] ?? null) === 'LodgingBusiness') {
                return $decoded;
            }
        }

        return [];
    }

    // ── What it says ─────────────────────────────────────────────────────

    public function test_a_guesthouse_publishes_itself_as_a_lodging_business(): void
    {
        $schema = $this->schema($this->property());

        $this->assertSame('LodgingBusiness', $schema['@type']);
        $this->assertSame('Maafushi View', $schema['name']);
        $this->assertSame('Eight rooms and a house reef, two minutes from the jetty.', $schema['description']);
        $this->assertSame('USD', $schema['currenciesAccepted']);
    }

    public function test_the_island_is_the_locality(): void
    {
        $schema = $this->schema($this->property());

        $this->assertSame('PostalAddress', $schema['address']['@type']);
        $this->assertSame('Maafushi', $schema['address']['addressLocality']);
        $this->assertSame('MV', $schema['address']['addressCountry']);
    }

    /**
     * An address naming only a country is no more use than no address, and
     * a guessed one is worse than both.
     */
    public function test_a_property_with_no_island_invents_none(): void
    {
        $schema = $this->schema($this->property(['island' => null]));

        $this->assertArrayNotHasKey('addressLocality', $schema['address']);
        $this->assertSame('MV', $schema['address']['addressCountry']);
    }

    public function test_the_check_in_and_check_out_times_are_published(): void
    {
        $schema = $this->schema($this->property());

        $this->assertStringStartsWith('14:00', $schema['checkinTime']);
        $this->assertStringStartsWith('11:00', $schema['checkoutTime']);
    }

    public function test_every_amenity_on_the_page_is_a_feature(): void
    {
        $schema = $this->schema($this->property());

        $named = array_column($schema['amenityFeature'], 'name');

        $this->assertSame(['Air conditioning', 'Wi-Fi'], $named);
        $this->assertTrue($schema['amenityFeature'][0]['value']);
    }

    // ── The money ────────────────────────────────────────────────────────

    /**
     * The column holds minor units. Publishing it raw would quote a
     * hundredfold nightly rate to every crawler that reads the page — and
     * unlike a visible price, nobody would notice.
     */
    public function test_the_nightly_rate_is_in_whole_units_not_cents(): void
    {
        $property = $this->property();

        RoomType::factory()->create([
            'property_id' => $property->id,
            'name' => ['en' => 'Sea-facing double'],
            'base_rate_minor' => 12_000,
            'quantity' => 3,
        ]);

        $offer = $this->schema($property->refresh())['makesOffer'][0];

        $this->assertSame('Sea-facing double', $offer['name']);
        $this->assertSame('120', $offer['priceSpecification']['price']);
        $this->assertSame('USD', $offer['priceSpecification']['priceCurrency']);
    }

    /**
     * Per night, said so. A crawler reading a one-night figure as the price
     * of a stay would advertise a week in the Maldives for the cost of one
     * evening.
     */
    public function test_the_rate_says_it_is_per_night(): void
    {
        $property = $this->property();
        RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 12_000]);

        $offer = $this->schema($property->refresh())['makesOffer'][0];

        $this->assertSame('UnitPriceSpecification', $offer['priceSpecification']['@type']);
        $this->assertSame('DAY', $offer['priceSpecification']['unitCode']);
    }

    /**
     * "From nothing" is not a price. A room with no rate yet carries no
     * offer at all, rather than an offer of zero.
     */
    public function test_an_unpriced_room_carries_no_offer(): void
    {
        $property = $this->property();
        RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 0]);

        $this->assertArrayNotHasKey('makesOffer', $this->schema($property->refresh()));
    }

    /**
     * Three doubles and a family room is four rooms, not two kinds of room
     * — the number a reader would count if they walked the corridor.
     */
    public function test_the_room_count_sums_the_quantities(): void
    {
        $property = $this->property();
        RoomType::factory()->create(['property_id' => $property->id, 'quantity' => 3, 'base_rate_minor' => 9_000]);
        RoomType::factory()->create(['property_id' => $property->id, 'quantity' => 1, 'base_rate_minor' => 15_000]);

        $this->assertSame(4, $this->schema($property->refresh())['numberOfRooms']);
    }

    // ── What it deliberately does not say ────────────────────────────────

    /**
     * There is no review system anywhere on this site, so there is nothing
     * to aggregate. Stars in the markup that the page does not show is the
     * exact policy violation the whole class is written around.
     */
    public function test_no_rating_is_invented(): void
    {
        $schema = $this->schema($this->property());

        $this->assertArrayNotHasKey('aggregateRating', $schema);
        $this->assertArrayNotHasKey('review', $schema);
        $this->assertArrayNotHasKey('starRating', $schema);
    }

    /**
     * The number on the page is Rihla's. Hanging it off the guesthouse's
     * node would publish the agency's switchboard as the property's own,
     * and a caller who reached Rihla expecting the front desk would be the
     * one to find out.
     */
    public function test_the_agencys_phone_number_is_not_the_guesthouses(): void
    {
        $schema = $this->schema($this->property());

        $this->assertArrayNotHasKey('telephone', $schema);
    }

    /**
     * The page shows no breadcrumb trail, so it publishes no BreadcrumbList.
     * Structured data describing navigation the page does not have is a
     * claim about the page rather than a description of it.
     */
    public function test_no_breadcrumb_is_published_for_a_page_that_shows_none(): void
    {
        $html = (string) $this->get('/en/stays/'.$this->property()->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('BreadcrumbList', $html);
    }

    /**
     * A draft guesthouse 404s, so nothing about it reaches a crawler. The
     * guard is on the page rather than only in the builder, because a page
     * that cannot be fetched cannot leak a name.
     */
    public function test_an_unpublished_property_publishes_nothing(): void
    {
        $property = Property::factory()->unpublished()->create(['name' => ['en' => 'Not open yet']]);

        $this->get('/en/stays/'.$property->slug)->assertNotFound();
    }

    /**
     * A title containing `</script>` must not close the block. All of this
     * is typed into the admin panel, so the escaping is not theoretical.
     */
    public function test_a_hostile_name_cannot_close_the_script_block(): void
    {
        $property = $this->property(['name' => ['en' => 'Maafushi</script><script>alert(1)</script>']]);

        $html = (string) $this->get('/en/stays/'.$property->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertStringContainsString('</script>', $html);
    }

    // ── The island holiday half — a TouristTrip that says where it goes ──

    /**
     * An island holiday runs on the package engine, so it publishes a
     * TouristTrip. What it could not say until now is *where*: "Fulidhoo
     * Weekend" names a destination only to somebody who already knows the
     * atoll, and an Umrah package's destination is implicit in the word
     * where this one's is not.
     */
    public function test_an_island_holiday_names_the_guesthouse_it_goes_to(): void
    {
        $property = $this->property();

        $package = Package::factory()->islandHoliday()->create([
            'title' => ['en' => 'Maafushi Weekend'],
            'property_id' => $property->id,
        ]);

        $schema = $this->packageSchema($package->slug);

        $this->assertSame('TouristTrip', $schema['@type']);
        $this->assertSame('LodgingBusiness', $schema['itinerary']['@type']);
        $this->assertSame('Maafushi View', $schema['itinerary']['name']);
        $this->assertSame('Maafushi', $schema['itinerary']['address']['addressLocality']);
        $this->assertStringContainsString($property->slug, $schema['itinerary']['url']);
    }

    /** A package with no guesthouse behind it invents no destination. */
    public function test_a_package_with_no_property_publishes_no_itinerary(): void
    {
        $package = Package::factory()->create(['title' => ['en' => 'Shawwal Umrah']]);

        $this->assertArrayNotHasKey('itinerary', $this->packageSchema($package->slug));
    }

    /**
     * A draft guesthouse is not a destination anybody may read about. The
     * holiday still publishes itself; it simply does not name a page that
     * 404s.
     */
    public function test_an_unpublished_guesthouse_is_not_named(): void
    {
        $property = Property::factory()->unpublished()->create(['name' => ['en' => 'Not open yet']]);

        $package = Package::factory()->islandHoliday()->create([
            'title' => ['en' => 'Somewhere Weekend'],
            'property_id' => $property->id,
        ]);

        $schema = $this->packageSchema($package->slug);

        $this->assertSame('TouristTrip', $schema['@type']);
        $this->assertArrayNotHasKey('itinerary', $schema);
    }

    /** @return array<string, mixed> */
    private function packageSchema(string $slug): array
    {
        $html = (string) $this->get('/en/packages/'.$slug)->assertOk()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);

            if (($decoded['@type'] ?? null) === 'TouristTrip') {
                return $decoded;
            }
        }

        return [];
    }
}
