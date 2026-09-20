<?php

namespace Tests\Feature;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\ArticleReference;
use App\Models\LocationMisconception;
use App\Models\Person;
use App\Models\ZiyarahLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The Ziyarah Guide — §7.2.
 *
 * Two things these hold down, in order of how badly they fail:
 *
 * 1. **Unreviewed religious content never reaches a URL.** A location page
 *    asserts history, significance and etiquette; §6.4's editorial gate is
 *    the same here as in the Knowledge Centre, and there is no preview link
 *    round it.
 * 2. **A correction is better sourced than the belief it corrects.** §7.2's
 *    whole argument for misconceptions is that naming what pilgrims are
 *    wrongly told prevents the innovations they are warned about — which
 *    only holds if the correction carries a source.
 */
class ZiyarahGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);
    }

    private function location(array $attributes = []): ZiyarahLocation
    {
        return ZiyarahLocation::factory()->create($attributes);
    }

    private function scholar(): Person
    {
        return Person::factory()->create(['name' => 'Sheikh Placeholder', 'role' => 'scholar']);
    }

    /** A location with its own source, ready to be signed off. */
    private function sourced(array $attributes = []): ZiyarahLocation
    {
        $location = $this->location($attributes);

        ArticleReference::factory()->create([
            'referenceable_type' => ZiyarahLocation::class,
            'referenceable_id' => $location->getKey(),
        ]);

        return $location->fresh();
    }

    private function live(array $attributes = []): ZiyarahLocation
    {
        $location = $this->sourced($attributes);
        $location->approve($this->scholar());
        $location->publish();

        return $location->fresh();
    }

    // ── The gate ─────────────────────────────────────────────────────────

    public function test_a_location_with_no_source_cannot_be_approved(): void
    {
        $location = $this->location();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('No source yet');

        $location->approve($this->scholar());
    }

    public function test_an_unapproved_location_cannot_be_published(): void
    {
        $location = $this->sourced();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('non-negotiable');

        $location->publish();
    }

    public function test_an_approved_location_publishes_and_names_its_scholar(): void
    {
        $location = $this->sourced();
        $location->approve($this->scholar(), 'Checked the citation.');
        $location->publish();

        $location = $location->fresh();

        $this->assertSame(ZiyarahLocation::PUBLISHED, $location->status);
        $this->assertSame('Sheikh Placeholder', $location->reviewer->name);
        $this->assertTrue($location->isLive());
    }

    public function test_withdrawing_needs_a_reason(): void
    {
        $location = $this->live();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('needs a reason');

        $location->withdraw('   ');
    }

    public function test_the_refusal_the_model_throws_is_the_one_the_screen_shows(): void
    {
        $location = $this->location();

        // Not two sentences that happen to agree. A screen explaining one
        // thing while the model enforces another is how a rule gets quietly
        // relaxed on one path.
        try {
            $location->approve($this->scholar());
            $this->fail('An unsourced location was approved.');
        } catch (EditorialStandardNotMet $e) {
            $this->assertSame($location->whyNotApprovable(), $e->getMessage());
        }
    }

    // ── A correction carries its own source ──────────────────────────────

    public function test_an_unsourced_correction_blocks_approval(): void
    {
        $location = $this->sourced();

        LocationMisconception::factory()->create(['ziyarah_location_id' => $location->getKey()]);

        $this->assertStringContainsString('no source', (string) $location->fresh()->whyNotApprovable());

        $this->expectException(EditorialStandardNotMet::class);

        $location->fresh()->approve($this->scholar());
    }

    public function test_a_sourced_correction_does_not_block_approval(): void
    {
        $location = $this->sourced();

        $misconception = LocationMisconception::factory()->create([
            'ziyarah_location_id' => $location->getKey(),
        ]);

        ArticleReference::factory()->create([
            'referenceable_type' => LocationMisconception::class,
            'referenceable_id' => $misconception->getKey(),
        ]);

        $this->assertNull($location->fresh()->whyNotApprovable());

        $location->fresh()->approve($this->scholar());

        $this->assertSame(ZiyarahLocation::APPROVED, $location->fresh()->status);
    }

    /** One correction and several read differently, and both get counted. */
    public function test_the_refusal_counts_the_unsourced_corrections(): void
    {
        $location = $this->sourced();

        LocationMisconception::factory()->create(['ziyarah_location_id' => $location->getKey()]);

        $this->assertStringContainsString('One correction', (string) $location->fresh()->whyNotApprovable());

        LocationMisconception::factory()->create(['ziyarah_location_id' => $location->getKey()]);

        $this->assertStringContainsString('2 corrections', (string) $location->fresh()->whyNotApprovable());
    }

    /**
     * A correction's source goes through the same grading rule.
     *
     * The polymorphic table is the reason: a second reference table for
     * misconceptions would have been a second copy of this check, and the
     * second copy is the one that drifts.
     */
    public function test_a_corrections_hadith_still_needs_a_grading(): void
    {
        $misconception = LocationMisconception::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry a grading');

        ArticleReference::factory()->create([
            'referenceable_type' => LocationMisconception::class,
            'referenceable_id' => $misconception->getKey(),
            'kind' => ArticleReference::HADITH,
            'grading' => null,
        ]);
    }

    // ── The public pages ─────────────────────────────────────────────────

    /**
     * `published_at` is stamped on every one of these deliberately.
     *
     * Without it the status check is never the thing doing the work — a
     * draft has no publish date, so a query that had forgotten the status
     * entirely would still exclude it and the test would pass. Stamping the
     * date leaves the status as the only thing standing between unreviewed
     * religious content and a URL, which is what this is supposed to prove.
     */
    public function test_only_a_published_location_has_a_page(): void
    {
        foreach ([ZiyarahLocation::DRAFT, ZiyarahLocation::IN_REVIEW, ZiyarahLocation::APPROVED, ZiyarahLocation::WITHDRAWN] as $status) {
            $location = $this->sourced(['slug' => 'not-live-'.$status]);
            $location->forceFill(['status' => $status, 'published_at' => now()->subDay()])->save();

            $this->get(route('ziyarah.show', $location->slug))->assertNotFound();
        }
    }

    /** The same, for the list. A leak there exposes every one of them. */
    public function test_the_index_lists_nothing_that_was_not_published(): void
    {
        foreach ([ZiyarahLocation::DRAFT, ZiyarahLocation::IN_REVIEW, ZiyarahLocation::APPROVED, ZiyarahLocation::WITHDRAWN] as $status) {
            $location = $this->sourced([
                'slug' => 'hidden-'.$status,
                'name' => ['en' => 'Hidden while '.$status],
            ]);
            $location->forceFill(['status' => $status, 'published_at' => now()->subDay()])->save();
        }

        $response = $this->get(route('ziyarah.index'))->assertOk();

        foreach (ZiyarahLocation::STATUSES as $status) {
            if ($status !== ZiyarahLocation::PUBLISHED) {
                $response->assertDontSee('Hidden while '.$status);
            }
        }

        $this->assertSame(0, $this->get(route('ziyarah.manifest'))->json('count'));
    }

    public function test_a_withdrawn_location_stops_being_reachable(): void
    {
        $location = $this->live(['slug' => 'withdrawn-one']);

        $this->get(route('ziyarah.show', 'withdrawn-one'))->assertOk();

        $location->withdraw('Replaced by a corrected page.');

        $this->get(route('ziyarah.show', 'withdrawn-one'))->assertNotFound();
    }

    public function test_the_index_lists_live_locations_and_groups_them_by_city(): void
    {
        $this->live(['slug' => 'in-makkah', 'name' => ['en' => 'A Makkah place'], 'city' => ZiyarahLocation::MAKKAH]);
        $this->live(['slug' => 'in-madinah', 'name' => ['en' => 'A Madinah place'], 'city' => ZiyarahLocation::MADINAH]);
        $this->sourced(['slug' => 'a-draft', 'name' => ['en' => 'A drafted place']]);

        $this->get(route('ziyarah.index'))
            ->assertOk()
            ->assertSee('A Makkah place')
            ->assertSee('A Madinah place')
            ->assertSee('Makkah')
            ->assertSee('Madinah')
            ->assertDontSee('A drafted place');
    }

    public function test_the_page_names_the_scholar_who_checked_it(): void
    {
        $location = $this->live(['slug' => 'named-scholar']);

        $this->get(route('ziyarah.show', $location->slug))
            ->assertOk()
            ->assertSee('Sheikh Placeholder');
    }

    /**
     * §7.1: a weak narration is kept and labelled. Removing it leaves a
     * pilgrim hearing it elsewhere with no correction.
     */
    public function test_a_weak_narration_is_labelled_on_the_page(): void
    {
        $location = $this->live(['slug' => 'with-a-weak-one']);

        ArticleReference::factory()->hadith(ArticleReference::DAIF)->create([
            'referenceable_type' => ZiyarahLocation::class,
            'referenceable_id' => $location->getKey(),
            'citation' => 'Placeholder narration for a test fixture',
        ]);

        $response = $this->get(route('ziyarah.show', $location->slug))
            ->assertOk()
            ->assertSee('Placeholder narration for a test fixture')
            ->assertSee("Da'if — weak");

        // Set apart, not a pale pill on a cream page. The label only does
        // its job — stopping a pilgrim repeating the narration — if it is
        // noticed, and a screenshot is what caught this reading as
        // decoration the first time.
        $response->assertSee('border-error/40', false);
    }

    /**
     * The button offers the same number the save then reports.
     *
     * The list page is saved too, and is how a pilgrim with no data finds
     * the others — so it counts. A button offering 3 that then says 4 reads
     * as a bug on the one screen that has to be trusted.
     */
    public function test_the_button_counts_the_index_page_too(): void
    {
        $this->live(['slug' => 'one']);
        $this->live(['slug' => 'two']);

        $this->get(route('ziyarah.index'))->assertOk()->assertSee('Save all 3 pages');

        $this->assertSame(2, $this->get(route('ziyarah.manifest'))->json('count'));
    }

    public function test_a_correction_shows_both_halves_and_its_source(): void
    {
        $location = $this->live(['slug' => 'with-a-correction']);

        $misconception = LocationMisconception::factory()->create([
            'ziyarah_location_id' => $location->getKey(),
            'belief' => ['en' => 'Placeholder belief on the page.'],
            'correction' => ['en' => 'Placeholder correction on the page.'],
        ]);

        ArticleReference::factory()->create([
            'referenceable_type' => LocationMisconception::class,
            'referenceable_id' => $misconception->getKey(),
            'citation' => 'Placeholder source for the correction',
        ]);

        $this->get(route('ziyarah.show', $location->slug))
            ->assertOk()
            ->assertSee('Placeholder belief on the page.')
            ->assertSee('Placeholder correction on the page.')
            ->assertSee('Placeholder source for the correction');
    }

    public function test_nearby_lists_the_same_city_and_not_the_page_itself(): void
    {
        $here = $this->live(['slug' => 'here', 'name' => ['en' => 'The page itself'], 'city' => ZiyarahLocation::MAKKAH]);
        $this->live(['slug' => 'next-door', 'name' => ['en' => 'Next door'], 'city' => ZiyarahLocation::MAKKAH]);
        $this->live(['slug' => 'far-away', 'name' => ['en' => 'Far away'], 'city' => ZiyarahLocation::MADINAH]);

        $response = $this->get(route('ziyarah.show', $here->slug))->assertOk();

        $response->assertSee('Next door');
        $response->assertDontSee('Far away');
    }

    // ── The map link, and the embed that is deliberately absent ──────────

    public function test_coordinates_produce_a_link_out_and_no_embedded_map(): void
    {
        $location = $this->live([
            'slug' => 'with-coordinates',
            'latitude' => 21.4224779,
            'longitude' => 39.8261818,
        ]);

        $response = $this->get(route('ziyarah.show', $location->slug))->assertOk();

        $response->assertSee('Open in maps');
        $response->assertSee('google.com/maps/search', false);

        // No iframe. Nobody has supplied a Maps key, and an unkeyed embed
        // renders a grey box stamped "for development purposes only" across
        // a page like this one.
        $response->assertDontSee('<iframe', false);
        $response->assertDontSee('maps.googleapis.com', false);
    }

    public function test_a_location_with_no_coordinates_offers_no_map_link(): void
    {
        $location = $this->live(['slug' => 'no-coordinates']);

        $this->get(route('ziyarah.show', $location->slug))
            ->assertOk()
            ->assertDontSee('Open in maps');
    }

    // ── The offline manifest ─────────────────────────────────────────────

    public function test_the_manifest_lists_the_index_and_every_live_page(): void
    {
        $this->live(['slug' => 'first', 'sort_order' => 1]);
        $this->live(['slug' => 'second', 'sort_order' => 2]);
        $this->sourced(['slug' => 'still-a-draft']);

        $manifest = $this->get(route('ziyarah.manifest'))->assertOk()->json();

        $this->assertSame(2, $manifest['count']);
        $this->assertContains(route('ziyarah.index'), $manifest['urls']);
        $this->assertContains(route('ziyarah.show', 'first'), $manifest['urls']);
        $this->assertContains(route('ziyarah.show', 'second'), $manifest['urls']);
        $this->assertNotContains(route('ziyarah.show', 'still-a-draft'), $manifest['urls']);
    }

    /**
     * The version has to move when a page changes, or a saved copy will
     * claim to be current while a pilgrim in Makkah reads a page that was
     * corrected a week ago.
     */
    public function test_the_version_changes_when_a_live_page_changes(): void
    {
        $location = $this->live(['slug' => 'versioned']);

        $before = $this->get(route('ziyarah.manifest'))->json('version');

        $this->travel(2)->minutes();
        $location->forceFill(['summary' => ['en' => 'A corrected summary.']])->save();

        $after = $this->get(route('ziyarah.manifest'))->json('version');

        $this->assertNotSame($before, $after);
    }

    public function test_withdrawing_a_page_takes_it_out_of_the_manifest(): void
    {
        $location = $this->live(['slug' => 'to-withdraw']);

        $this->assertContains(
            route('ziyarah.show', 'to-withdraw'),
            $this->get(route('ziyarah.manifest'))->json('urls'),
        );

        $location->withdraw('Corrected elsewhere.');

        $manifest = $this->get(route('ziyarah.manifest'))->json();

        $this->assertSame(0, $manifest['count']);
        $this->assertNotContains(route('ziyarah.show', 'to-withdraw'), $manifest['urls']);
    }

    public function test_the_index_offers_the_save_control_only_when_there_is_something_to_save(): void
    {
        $this->get(route('ziyarah.index'))
            ->assertOk()
            ->assertDontSee('data-ziyarah-offline', false)
            ->assertSee('Nothing here yet');

        $this->live(['slug' => 'something']);

        $this->get(route('ziyarah.index'))
            ->assertOk()
            ->assertSee('data-ziyarah-offline', false)
            ->assertSee(route('ziyarah.manifest'), false);
    }

    // ── Nothing ships with this feature ──────────────────────────────────

    /**
     * No seeded location, for the same reason no article is seeded: there
     * is no named religious reviewer yet, and AGENTS.md records what
     * machine-filled religious content has already cost this site.
     */
    public function test_no_location_is_seeded(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertSame(0, ZiyarahLocation::count());
        $this->assertSame(0, LocationMisconception::count());
    }

    public function test_the_sitemap_lists_nothing_until_a_page_is_live(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/ziyarah');

        $this->live(['slug' => 'listed-now']);
        Cache::forget('sitemap.xml');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/en/ziyarah</loc>', false)
            ->assertSee('/en/ziyarah/listed-now', false);
    }

    public function test_every_status_and_city_has_a_sentence(): void
    {
        foreach (ZiyarahLocation::STATUSES as $status) {
            $this->assertNotSame('Unknown', (new ZiyarahLocation(['status' => $status]))->statusLabel());
        }

        foreach (ZiyarahLocation::CITIES as $city) {
            $this->assertNotSame('Unknown', (new ZiyarahLocation(['city' => $city]))->cityLabel());
        }
    }
}
