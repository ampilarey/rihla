<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RoomType;
use App\Models\Stay;
use App\Support\Audience;
use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The marketplace search — §16.7, §16 Phase 13.1.
 *
 * `/stays` keeps its three door cards and lists every listing behind a
 * door that is not off. Separate from `StaysPublicPagesTest`, which covers
 * the strand pages and the listing page.
 */
class StaysSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Services::save([
            'stays_guesthouses' => Services::ON,
            'stays_island_holidays' => Services::OFF,
            'stays_rooms' => Services::ON,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function listing(string $name, array $attributes = [], int $rate = 8000, ?int $local = null): Property
    {
        $property = Property::factory()->create([
            'name' => ['en' => $name],
            'island' => 'Maafushi',
            'currency' => 'USD',
            'min_nights' => 1,
            ...$attributes,
        ]);

        RoomType::factory()->create([
            'property_id' => $property->id,
            'quantity' => 1,
            'sleeps' => 2,
            'base_rate_minor' => $rate,
            'local_rate_minor' => $local,
        ]);

        return $property;
    }

    // ── Who is listed ────────────────────────────────────────────────────

    public function test_it_lists_every_listing_behind_an_open_door(): void
    {
        $this->listing('Coral Garden Inn');
        $this->listing('Malé City Room', ['type' => Property::RENTAL, 'currency' => 'MVR']);

        $this->get('/en/stays')
            ->assertOk()
            ->assertSee('Find a place to stay')
            ->assertSee('Coral Garden Inn')
            ->assertSee('Malé City Room')
            ->assertSee('2 places to stay');
    }

    /**
     * Only approved listings from hosts Rihla has checked and who are
     * active — and the same rule on the strand and listing pages, so a
     * listing is never half-suspended.
     */
    public function test_only_approved_listings_from_verified_active_hosts_are_shown(): void
    {
        $live = $this->listing('Live Lodge');

        $draft = $this->listing('Draft Lodge');
        $draft->forceFill(['approval' => Property::PENDING])->save();

        // Active but never checked: each half of the host rule on its own.
        $unverified = $this->listing('Unchecked Lodge', [
            'partner_id' => Partner::factory()->create(['verification' => Partner::UNVERIFIED])->id,
        ]);

        $suspended = $this->listing('Suspended Lodge');
        $suspended->partner->forceFill(['status' => Partner::STATUS_SUSPENDED])->save();

        $unpublished = $this->listing('Hidden Lodge', ['is_published' => false]);

        $this->get('/en/stays')
            ->assertSee('Live Lodge')
            ->assertDontSee('Draft Lodge')
            ->assertDontSee('Unchecked Lodge')
            ->assertDontSee('Suspended Lodge')
            ->assertDontSee('Hidden Lodge');

        $this->get('/en/stays/guesthouses')
            ->assertSee('Live Lodge')
            ->assertDontSee('Draft Lodge')
            ->assertDontSee('Unchecked Lodge')
            ->assertDontSee('Suspended Lodge');

        $this->get('/en/stays/'.$live->slug)->assertOk();

        foreach ([$draft, $unverified, $suspended, $unpublished] as $hidden) {
            $this->get('/en/stays/'.$hidden->slug)->assertNotFound();
            $this->get('/en/stays/'.$hidden->slug.'/sheet.pdf')->assertNotFound();
        }
    }

    public function test_a_listing_behind_a_door_that_is_off_is_not_listed(): void
    {
        Services::save(['stays_guesthouses' => Services::ON, 'stays_rooms' => Services::OFF]);

        $this->listing('Island Inn');
        $this->listing('Malé Flat', ['type' => Property::RENTAL]);

        $this->get('/en/stays')
            ->assertSee('Island Inn')
            ->assertDontSee('Malé Flat');
    }

    // ── Tourists and locals ──────────────────────────────────────────────

    public function test_a_visitor_sees_the_tourist_price_and_a_local_the_local_one(): void
    {
        $this->listing('Both Prices', rate: 8000, local: 90000);
        $this->listing('Tourists Only', rate: 6000);

        $this->get('/en/stays')
            ->assertSee('Both Prices')
            ->assertSee('Tourists Only')
            ->assertSee('USD 80', false);

        $this->get('/en/stays?audience=local')
            ->assertSee('Both Prices')
            ->assertSee('MVR 900', false)
            // Not sold to locals at all, so not shown to them at all.
            ->assertDontSee('Tourists Only');
    }

    /** A Dhivehi reader is taken to be local until they say otherwise. */
    public function test_the_audience_defaults_from_the_language(): void
    {
        $this->listing('Tourists Only', rate: 6000);

        $this->get('/dv/stays')->assertDontSee('Tourists Only');
        $this->get('/dv/stays?audience=tourist')->assertSee('Tourists Only');
    }

    /** A local season alone is a local price, as Availability::offers() says. */
    public function test_a_local_season_alone_puts_a_listing_in_front_of_locals(): void
    {
        $property = $this->listing('Season Only');
        Rate::create([
            'room_type_id' => $property->roomTypes()->sole()->id,
            'audience' => Audience::LOCAL,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
            'rate_minor' => 100000,
        ]);

        $this->get('/en/stays?audience=local')->assertSee('Season Only');
    }

    // ── Filters ──────────────────────────────────────────────────────────

    public function test_it_filters_by_atoll_kind_and_price(): void
    {
        $this->listing('Kaafu Guesthouse', ['atoll' => 'Kaafu', 'kind' => Property::KIND_GUESTHOUSE], rate: 5000);
        $this->listing('Alifu Apartment', ['atoll' => 'Alifu Dhaalu', 'kind' => Property::KIND_APARTMENT], rate: 15000);

        $this->get('/en/stays?atoll=Kaafu')
            ->assertSee('Kaafu Guesthouse')
            ->assertDontSee('Alifu Apartment');

        $this->get('/en/stays?kind[]=apartment')
            ->assertSee('Alifu Apartment')
            ->assertDontSee('Kaafu Guesthouse');

        $this->get('/en/stays?price_min=100')
            ->assertSee('Alifu Apartment')
            ->assertDontSee('Kaafu Guesthouse');

        // Typed the wrong way round, read the way it was meant.
        $this->get('/en/stays?price_min=60&price_max=10')
            ->assertSee('Kaafu Guesthouse')
            ->assertDontSee('Alifu Apartment');
    }

    /** Nothing is converted, so a rufiyaa listing is not compared with a dollar range. */
    public function test_a_price_range_never_compares_across_currencies(): void
    {
        $this->listing('Dollar Inn', rate: 5000);
        $this->listing('Rufiyaa Room', ['type' => Property::RENTAL, 'currency' => 'MVR'], rate: 5000);

        $this->get('/en/stays?price_max=100')
            ->assertSee('Dollar Inn')
            ->assertDontSee('Rufiyaa Room');
    }

    /** Dates go through Availability — the same answer the booking path gives. */
    public function test_a_listing_with_nothing_free_on_the_dates_is_left_out(): void
    {
        $full = $this->listing('Full House');
        $this->listing('Free House');

        Stay::factory()->create([
            'property_id' => $full->id,
            'room_type_id' => $full->roomTypes()->sole()->id,
            'check_in' => '2027-03-01',
            'check_out' => '2027-03-10',
            'status' => Stay::CONFIRMED,
        ]);

        $this->get('/en/stays?from=2027-03-03&to=2027-03-05')
            ->assertSee('Free House')
            ->assertDontSee('Full House');
    }

    public function test_it_sorts_by_price_with_unpriced_listings_last(): void
    {
        $this->listing('Pricey Place', rate: 30000);
        $this->listing('Cheap Place', rate: 4000);
        $this->listing('Middle Place', rate: 9000);

        $this->get('/en/stays?sort=price')
            ->assertSeeInOrder(['Cheap Place', 'Middle Place', 'Pricey Place']);
    }

    public function test_it_pages_at_twenty_four(): void
    {
        foreach (range(1, 25) as $n) {
            $this->listing(sprintf('Listing %02d', $n), ['sort_order' => $n]);
        }

        $this->get('/en/stays')
            ->assertSee('25 places to stay')
            ->assertSee('Listing 24')
            ->assertDontSee('Listing 25');

        $this->get('/en/stays?page=2')
            ->assertSee('Listing 25')
            ->assertDontSee('Listing 01');
    }

    /** A hand-edited URL shows listings, not a validation page. */
    public function test_nonsense_in_the_query_is_ignored(): void
    {
        $this->listing('Still Here');

        $this->get('/en/stays?audience=martian&sort=chaos&kind[]=castle&price_min=-5&guests=zero')
            ->assertOk()
            ->assertSee('Still Here');
    }

    /** The audience a visitor chose follows them to the listing. */
    public function test_a_chosen_audience_is_carried_to_the_listing(): void
    {
        $property = $this->listing('Carried', local: 90000);

        $this->get('/en/stays?audience=local')
            ->assertSee(route('stays.show', ['property' => $property->slug, 'audience' => 'local']), false);
    }
}
