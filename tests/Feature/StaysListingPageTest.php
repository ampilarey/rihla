<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\RoomType;
use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The listing page as the marketplace needs it — §16.7, §16 Phase 13.2.
 *
 * Prices for the guest who is actually reading, the gallery and the map.
 * `StaysPublicPagesTest` still covers the page's original half: the policy,
 * the translation fallback, the share kit.
 */
class StaysListingPageTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        Services::save(['stays_guesthouses' => Services::ON]);
        config(['stays.green_tax.amount_minor' => 600]);

        $this->property = Property::factory()->create([
            'currency' => 'USD',
            'min_nights' => 1,
            'partner_id' => Partner::factory()->create(['green_tax_mode' => Partner::GREEN_TAX_AT_PROPERTY])->id,
        ]);

        RoomType::factory()->create([
            'property_id' => $this->property->id,
            'name' => ['en' => 'Lagoon Double'],
            'quantity' => 2,
            'base_rate_minor' => 8000,
            'local_rate_minor' => 90000,
        ]);

        RoomType::factory()->create([
            'property_id' => $this->property->id,
            'name' => ['en' => 'Tourist Suite'],
            'quantity' => 1,
            'base_rate_minor' => 20000,
            'local_rate_minor' => null,
        ]);
    }

    private function url(string $query = ''): string
    {
        return '/en/stays/'.$this->property->slug.($query !== '' ? '?'.$query : '');
    }

    public function test_a_visitor_sees_tourist_prices_and_the_green_tax(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('USD 80', false)
            ->assertSee('USD 200', false)
            ->assertDontSee('Not offered at local prices')
            ->assertSee('Green tax is paid at the guesthouse, not here.');
    }

    /** One calendar, two prices — and a room not sold to locals says so. */
    public function test_a_local_sees_rufiyaa_no_green_tax_and_which_room_is_not_theirs(): void
    {
        $this->get($this->url('audience=local'))
            ->assertOk()
            ->assertSee('MVR 900', false)
            ->assertSee('Tourist Suite')
            ->assertSee('Not offered at local prices')
            ->assertDontSee('USD 200', false)
            ->assertDontSee('Green tax is paid at the guesthouse, not here.');
    }

    public function test_a_local_with_dates_is_quoted_the_stay_in_rufiyaa(): void
    {
        $this->get($this->url('audience=local&from=2027-03-03&to=2027-03-05'))
            ->assertOk()
            ->assertSee('MVR 1,800', false);
    }

    public function test_a_dhivehi_reader_is_shown_local_prices_until_they_say_otherwise(): void
    {
        $this->get('/dv/stays/'.$this->property->slug)->assertSee('MVR 900', false);
        $this->get('/dv/stays/'.$this->property->slug.'?audience=tourist')->assertSee('USD 80', false);
    }

    // ── The gallery ──────────────────────────────────────────────────────

    public function test_each_photo_is_a_button_that_opens_the_lightbox(): void
    {
        PropertyPhoto::factory()->count(3)->create(['property_id' => $this->property->id]);
        $last = PropertyPhoto::latest('id')->first();

        $this->get($this->url())
            // The lightbox reads its images from the data, not from markup
            // rendered inside the closed dialog — which never loaded.
            ->assertSee(basename($last->path), false)
            ->assertOk()
            ->assertSee('Open photo 1 of 3')
            ->assertSee('Open photo 3 of 3')
            ->assertSee('<dialog x-ref="lightbox"', false)
            ->assertSee('<form method="dialog">', false);
    }

    public function test_no_photos_means_no_gallery(): void
    {
        $this->get($this->url())->assertDontSee('x-ref="lightbox"', false);
    }

    // ── The map ──────────────────────────────────────────────────────────

    public function test_a_placed_listing_has_a_map_and_the_policy_lets_its_tiles_in(): void
    {
        $this->property->update(['latitude' => 3.9412, 'longitude' => 73.4903]);

        $response = $this->get($this->url());

        $response->assertOk()
            ->assertSee('id="stay-map"', false)
            ->assertSee('data-lat="3.9412000"', false)
            ->assertSee('openstreetmap.org/?mlat=3.9412000', false);

        $this->assertStringContainsString('https://tile.openstreetmap.org', (string) $response->headers->get('Content-Security-Policy'));
    }

    /** Nobody has placed it, so there is nothing to show — never a guess. */
    public function test_an_unplaced_listing_has_no_map(): void
    {
        $this->get($this->url())->assertDontSee('id="stay-map"', false);
    }

    /** Factories run unguarded; the coordinates must survive the real path too. */
    public function test_the_coordinates_are_fillable(): void
    {
        $this->property->update(['latitude' => '4.1755', 'longitude' => '73.5093', 'atoll' => 'Kaafu', 'kind' => Property::KIND_APARTMENT]);

        $fresh = $this->property->fresh();
        $this->assertSame('4.1755000', $fresh->latitude);
        $this->assertSame('Kaafu', $fresh->atoll);
        $this->assertSame(Property::KIND_APARTMENT, $fresh->kind);
    }
}
