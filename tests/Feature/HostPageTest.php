<?php

namespace Tests\Feature;

use App\Filament\Host\Pages\MyPage;
use App\Http\Controllers\HostPageController;
use App\Models\HostMembership;
use App\Models\HostPage;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Brand;
use App\Support\HostRole;
use App\Support\Services;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A host's own page — §16.8, §16 Phase 14.4.
 */
class HostPageTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private HostPage $page;

    private Property $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Services::save(['stays_guesthouses' => Services::ON]);

        $this->host = Partner::factory()->create(['name' => 'Coral Garden Inn', 'registration_number' => 'MOT-GH-2024-118']);
        $this->page = HostPage::factory()->published()->create([
            'partner_id' => $this->host->id,
            'tagline' => ['en' => 'Two minutes from the harbour.'],
            'about' => ['en' => 'Our family has welcomed guests since 2016.'],
        ]);
        $this->listing = Property::factory()->create(['partner_id' => $this->host->id, 'name' => ['en' => 'The Garden House']]);
        RoomType::factory()->create(['property_id' => $this->listing->id, 'base_rate_minor' => 8000]);
    }

    private function url(string $locale = 'en'): string
    {
        return '/'.$locale.'/stays/hosts/'.$this->host->slug;
    }

    // ── Who sees it ──────────────────────────────────────────────────────

    public function test_a_published_page_shows_the_host_their_places_and_the_frame(): void
    {
        $draft = Property::factory()->create(['partner_id' => $this->host->id, 'name' => ['en' => 'Not Yet Approved']]);
        $draft->forceFill(['approval' => Property::DRAFT])->save();

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Coral Garden Inn')
            ->assertSee('Two minutes from the harbour.')
            ->assertSee('Our family has welcomed guests since 2016.')
            ->assertSee('The Garden House')
            ->assertDontSee('Not Yet Approved')
            ->assertSee('Checked by Rihla')
            ->assertSee('MOT-GH-2024-118')
            ->assertSee('Booked through Rihla Travels.');
    }

    public function test_the_contact_links_are_the_hosts_own(): void
    {
        $this->page->forceFill(['whatsapp' => '+960 777 1234', 'website_url' => 'https://coralgarden.example'])->save();

        $this->get($this->url())
            ->assertSee('https://wa.me/9607771234', false)
            ->assertSee('https://coralgarden.example', false);
    }

    public function test_an_unpublished_page_is_not_there(): void
    {
        $this->page->forceFill(['published_at' => null])->save();

        $this->get($this->url())->assertNotFound();
    }

    /** Published or not, a host guests may not see has no page. */
    public function test_a_suspended_or_unchecked_host_has_no_page(): void
    {
        $this->host->forceFill(['status' => Partner::STATUS_SUSPENDED])->save();
        $this->get($this->url())->assertNotFound();

        $this->host->forceFill(['status' => Partner::STATUS_ACTIVE, 'verification' => Partner::VERIFICATION_PENDING])->save();
        $this->get($this->url())->assertNotFound();
    }

    public function test_a_signed_preview_shows_an_unpublished_page_and_is_not_indexed(): void
    {
        $this->page->forceFill(['published_at' => null])->save();
        $preview = HostPageController::previewUrl($this->host);

        $this->get($preview)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('this page is not published yet');

        $this->get(str_replace('signature=', 'signature=x', $preview))->assertNotFound();
        $this->get($this->url().'?preview=1')->assertNotFound();
    }

    public function test_the_story_falls_back_to_english_and_says_so(): void
    {
        $this->get($this->url('dv'))
            ->assertOk()
            ->assertSee('Our family has welcomed guests since 2016.')
            ->assertSee(__('messages.This host has not written their story in your language yet, so it is shown in English.', [], 'dv'));
    }

    public function test_the_two_layouts_put_different_things_first(): void
    {
        $this->get($this->url())->assertSeeInOrder([__('messages.Our story'), __('messages.Where you can stay')]);

        $this->page->forceFill(['layout' => HostPage::GRID])->save();

        $this->get($this->url())->assertSeeInOrder([__('messages.Where you can stay'), __('messages.Our story')]);
    }

    public function test_a_section_switched_off_is_not_shown(): void
    {
        $this->page->forceFill(['sections' => ['gallery', 'map', 'faq', 'contact']])->save();

        $this->get($this->url())->assertDontSee('Our family has welcomed guests since 2016.');
    }

    // ── The share kit ────────────────────────────────────────────────────

    public function test_the_page_carries_structured_data_and_a_share_card_of_its_cover(): void
    {
        Storage::fake('public');
        $cover = UploadedFile::fake()->image('cover.jpg', 1600, 900)->store('host-pages', 'public');
        $this->page->forceFill(['cover_path' => $cover])->save();

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"Organization"', $html);
        $this->assertStringContainsString('"subOrganization"', $html);
        $this->assertStringContainsString('"@type":"LodgingBusiness"', $html);
        $this->assertStringNotContainsString('aggregateRating', $html, 'No rating until a published review exists.');

        preg_match('#stays/hosts/'.preg_quote($this->host->slug, '#').'/card\.png\?v=([0-9a-f]+)#', (string) $html, $match);
        $this->assertNotEmpty($match, 'The OG image is the host\'s own card, versioned by its cover.');

        $this->get('/en/stays/hosts/'.$this->host->slug.'/card.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_the_sitemap_lists_published_pages_only(): void
    {
        $other = Partner::factory()->create();
        HostPage::factory()->create(['partner_id' => $other->id]);
        Cache::forget('sitemap.xml');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/stays/hosts/'.$this->host->slug)
            ->assertDontSee('/stays/hosts/'.$other->slug);
    }

    public function test_a_listing_links_to_its_hosts_page_only_when_published(): void
    {
        $this->get('/en/stays/'.$this->listing->slug)->assertSee($this->url(), false);

        $this->page->forceFill(['published_at' => null])->save();

        $this->get('/en/stays/'.$this->listing->slug)->assertDontSee('/stays/hosts/'.$this->host->slug, false);
    }

    // ── The editor ───────────────────────────────────────────────────────

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    public function test_the_owner_edits_their_words_colours_and_questions(): void
    {
        $owner = $this->member(HostRole::OWNER);
        $this->inPanel($owner);

        Livewire::actingAs($owner)
            ->test(MyPage::class)
            ->fillForm([
                'layout' => HostPage::GRID,
                'font' => 'serif',
                'colour_primary' => '#0A5754',
                'colour_accent' => '#0A5754',
                'tagline' => ['en' => 'Sunrise over the lagoon', 'dv' => ''],
                'faq' => [['question' => ['en' => 'Is breakfast included?'], 'answer' => ['en' => 'Yes, every morning.']]],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $page = $this->page->fresh();
        $this->assertSame(HostPage::GRID, $page->layout);
        $this->assertSame('Sunrise over the lagoon', $page->getTranslation('tagline', 'en'));
        $this->assertFalse($page->hasTranslation('tagline', 'dv'), 'A blanked language is cleared, not stored empty.');
        $this->assertSame('#FFFFFF', $page->onAccent());

        $this->get($this->url())
            ->assertSee('Is breakfast included?')
            ->assertSee('color: #0A5754', false)
            ->assertSee("Georgia, 'Times New Roman', serif");
    }

    /** The plan's plant: a colour pair under 4.5:1 is refused on the page editor. */
    public function test_an_unreadable_colour_is_refused(): void
    {
        $owner = $this->member(HostRole::OWNER);
        $this->inPanel($owner);

        Livewire::actingAs($owner)
            ->test(MyPage::class)
            ->fillForm(['colour_primary' => Brand::GOLD])   // gold on the cream page, ~1.3:1
            ->call('save')
            ->assertHasFormErrors(['colour_primary']);

        Livewire::actingAs($owner)
            ->test(MyPage::class)
            ->fillForm(['colour_accent' => '#777777'])      // white 4.48:1, dark words worse
            ->call('save')
            ->assertHasFormErrors(['colour_accent']);

        $this->assertNull($this->page->fresh()->colour_primary);
        $this->assertNull($this->page->fresh()->colour_accent);
    }

    public function test_publishing_and_taking_offline(): void
    {
        $this->page->forceFill(['published_at' => null])->save();
        $owner = $this->member(HostRole::OWNER);
        $this->inPanel($owner);

        Livewire::actingAs($owner)->test(MyPage::class)->callAction('publish');
        $this->assertTrue($this->page->fresh()->isPublished());

        Livewire::actingAs($owner)->test(MyPage::class)->callAction('unpublish');
        $this->assertFalse($this->page->fresh()->isPublished());
    }

    public function test_reception_does_not_edit_the_page(): void
    {
        $reception = $this->member(HostRole::RECEPTION);

        $this->actingAs($reception)->get('/host/'.$this->host->slug.'/my-page')->assertForbidden();
    }
}
