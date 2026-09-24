<?php

namespace Tests\Feature;

use App\Models\WhyFeature;
use App\Models\WhySection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The homepage's "why Rihla" section is never shown stale.
 *
 * `HomeController` caches the active section and its features for an hour
 * under `WhySection::CACHE_KEY`. That cache was cleared by four hand-written
 * calls in the two Blade controllers and nowhere else, so a section or a
 * feature saved by any other route — the staff panel it is moving to, a
 * seeder, tinker — left the homepage showing the old words for up to an
 * hour. No error; nothing to suggest the save had not taken.
 *
 * These tests write through the model directly, deliberately: that is the
 * route with no controller in front of it, which is exactly the one that
 * used to leave the cache standing.
 */
class WhySectionCacheTest extends TestCase
{
    use RefreshDatabase;

    private function primeCache(): void
    {
        Cache::put(WhySection::CACHE_KEY, 'stale', 3600);
        $this->assertTrue(Cache::has(WhySection::CACHE_KEY));
    }

    public function test_saving_a_section_clears_the_homepage_cache(): void
    {
        $section = WhySection::create(['title' => ['en' => 'Why Rihla'], 'is_active' => true]);
        $this->primeCache();

        $section->update(['title' => ['en' => 'Why travel with Rihla']]);

        $this->assertFalse(Cache::has(WhySection::CACHE_KEY));
    }

    public function test_deleting_a_section_clears_it(): void
    {
        $section = WhySection::create(['title' => ['en' => 'Why Rihla'], 'is_active' => true]);
        $this->primeCache();

        $section->delete();

        $this->assertFalse(Cache::has(WhySection::CACHE_KEY));
    }

    /** A feature lives inside its section's cached entry. */
    public function test_saving_a_feature_clears_it(): void
    {
        $section = WhySection::create(['title' => ['en' => 'Why Rihla'], 'is_active' => true]);
        $feature = WhyFeature::create(['why_section_id' => $section->id, 'title' => ['en' => 'Licensed'], 'sort_order' => 0]);
        $this->primeCache();

        $feature->update(['title' => ['en' => 'Licensed by the Ministry']]);

        $this->assertFalse(Cache::has(WhySection::CACHE_KEY));
    }

    public function test_adding_and_deleting_a_feature_clears_it(): void
    {
        $section = WhySection::create(['title' => ['en' => 'Why Rihla'], 'is_active' => true]);
        $this->primeCache();

        $feature = WhyFeature::create(['why_section_id' => $section->id, 'title' => ['en' => 'Licensed'], 'sort_order' => 0]);
        $this->assertFalse(Cache::has(WhySection::CACHE_KEY), 'Adding a feature left the homepage cached.');

        $this->primeCache();
        $feature->delete();
        $this->assertFalse(Cache::has(WhySection::CACHE_KEY), 'Deleting a feature left the homepage cached.');
    }

    /**
     * The end-to-end version: what a visitor actually sees. The homepage
     * shows the edit on the very next request, not an hour later.
     */
    public function test_the_homepage_shows_an_edit_straight_away(): void
    {
        $section = WhySection::create(['title' => ['en' => 'Why Choose Rihla'], 'is_active' => true]);
        WhyFeature::create(['why_section_id' => $section->id, 'title' => ['en' => 'Guided by scholars'], 'sort_order' => 0, 'is_active' => true]);

        $this->get('/en')->assertOk()->assertSee('Guided by scholars');

        $section->features()->first()->update(['title' => ['en' => 'Led by qualified scholars']]);

        $this->get('/en')->assertOk()
            ->assertSee('Led by qualified scholars')
            ->assertDontSee('Guided by scholars');
    }
}
