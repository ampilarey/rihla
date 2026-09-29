<?php

namespace Tests\Feature;

use App\Models\HostPage;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Browse by atoll and the host directory — §16 Phase 15.
 */
class StaysDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Services::save(['stays_guesthouses' => Services::ON]);
    }

    private function listing(array $attributes = [], ?Partner $host = null): Property
    {
        $property = Property::factory()->create($attributes + ['partner_id' => ($host ?? Partner::factory()->create())->id]);
        RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 8000]);

        return $property;
    }

    private function host(string $name, bool $published = true): Partner
    {
        $host = Partner::factory()->create(['name' => $name]);
        $factory = HostPage::factory();
        ($published ? $factory->published() : $factory)->create(['partner_id' => $host->id, 'tagline' => ['en' => 'The tagline of '.$name]]);

        return $host;
    }

    // ── Atolls ───────────────────────────────────────────────────────────

    public function test_each_atoll_counts_what_the_search_it_links_to_will_show(): void
    {
        $this->listing(['atoll' => 'Kaafu', 'island' => 'Maafushi']);
        $this->listing(['atoll' => 'Kaafu', 'island' => 'Thulusdhoo']);
        $this->listing(['atoll' => 'Alif Dhaal', 'island' => 'Dhigurah']);
        // Not listable: nothing to count.
        $this->listing(['atoll' => 'Baa', 'island' => 'Dharavandhoo', 'is_published' => false]);
        $this->listing(['atoll' => 'Laamu', 'island' => 'Gan'], Partner::factory()->unverified()->create());
        // No atoll recorded: left off, not filed under a guess.
        $this->listing(['atoll' => null, 'island' => 'Somewhere Unfiled']);

        $response = $this->get('/en/stays/atolls')->assertOk();

        $response->assertSeeInOrder(['Alif Dhaal', '1 place to stay', 'Dhigurah', 'Kaafu', '2 places to stay', 'Maafushi, Thulusdhoo'])
            ->assertDontSee('Dharavandhoo')
            ->assertDontSee('Laamu')
            ->assertDontSee('Somewhere Unfiled')
            ->assertSee(route('stays.index', ['atoll' => 'Kaafu']), false);

        // The link opens exactly the count on its card.
        $this->get(route('stays.index', ['atoll' => 'Kaafu']))->assertSee('2 places to stay');
    }

    public function test_a_local_is_counted_only_what_a_local_can_book(): void
    {
        $this->listing(['atoll' => 'Kaafu']);
        $withLocalPrice = $this->listing(['atoll' => 'Vaavu']);
        $withLocalPrice->roomTypes()->update(['local_rate_minor' => 90000]);

        $this->get('/en/stays/atolls?audience=local')->assertOk()
            ->assertSee('Vaavu')
            ->assertDontSee('Kaafu')
            ->assertSee(e(route('stays.index', ['atoll' => 'Vaavu', 'audience' => 'local'])), false);
    }

    public function test_every_door_off_means_no_atoll_page(): void
    {
        Services::save(['stays_guesthouses' => Services::OFF, 'stays_rooms' => Services::OFF]);

        $this->get('/en/stays/atolls')->assertNotFound();
        $this->get('/en/stays/hosts')->assertNotFound();
    }

    /** "atolls" is a route now; a listing may not take it as a slug. */
    public function test_a_listing_cannot_be_called_atolls(): void
    {
        $property = $this->listing(['slug' => 'atolls']);

        $this->assertSame('atolls-stay', $property->slug);
    }

    // ── Hosts ────────────────────────────────────────────────────────────

    public function test_the_directory_lists_hosts_a_guest_may_open_and_links_to_their_pages(): void
    {
        $coral = $this->host('Coral Garden Inn');
        $this->listing(['atoll' => 'Kaafu', 'island' => 'Maafushi'], $coral);
        $this->listing(['atoll' => 'Kaafu', 'island' => 'Maafushi'], $coral);

        $blue = $this->host('Blue Lagoon Rooms');
        $this->listing(['atoll' => 'Vaavu', 'island' => 'Fulidhoo'], $blue);

        // Four that must not appear.
        $this->listing([], $this->host('Draft Page House', published: false));
        $this->host('Nothing Listed Lodge');
        $suspended = $this->host('Suspended Stays');
        $this->listing([], $suspended);
        $suspended->forceFill(['status' => Partner::STATUS_SUSPENDED])->save();
        $unapproved = $this->host('Awaiting Approval Inn');
        $this->listing(['approval' => Property::PENDING], $unapproved);

        $this->get('/en/stays/hosts')->assertOk()
            ->assertSeeInOrder(['Blue Lagoon Rooms', 'Fulidhoo, Vaavu', '1 place to stay', 'Coral Garden Inn', 'The tagline of Coral Garden Inn', 'Maafushi, Kaafu', '2 places to stay'])
            ->assertSee(route('stays.host', ['partner' => $coral->slug]), false)
            ->assertDontSee('Draft Page House')
            ->assertDontSee('Nothing Listed Lodge')
            ->assertDontSee('Suspended Stays')
            ->assertDontSee('Awaiting Approval Inn');
    }

    public function test_the_search_page_links_to_both_and_the_sitemap_lists_them(): void
    {
        $this->listing(['atoll' => 'Kaafu']);

        $this->get('/en/stays')->assertOk()
            ->assertSee(route('stays.atolls'), false)
            ->assertSee(route('stays.hosts'), false);

        $this->get('/sitemap.xml')->assertOk()
            ->assertSee('/en/stays/atolls', false)
            ->assertSee('/en/stays/hosts', false);
    }
}
