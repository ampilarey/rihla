<?php

namespace Tests\Feature;

use App\Models\HeroBanner;
use App\Models\Media;
use App\Models\Trip;
use App\Models\User;
use App\Models\WhyFeature;
use App\Models\WhySection;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saving, not just opening.
 *
 * RouteSmokeTest walks every GET route, which catches a screen that will not
 * render. It cannot catch a screen that renders and then fails on submit —
 * which is what the Umrah guide admin did for months: the form appeared, and
 * saving threw "no column named summary".
 *
 * Every admin resource is exercised here through the real routes, with the
 * payload its own validation rules ask for.
 */
class AdminWritePathTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    public function test_a_trip_can_be_created_updated_and_deleted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.trips.store'), [
            'title' => 'Ramadan Umrah 2026',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-10',
            'status' => 'upcoming',
            'price_from_mvr' => 38000,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $trip = Trip::sole();
        $this->assertSame('Ramadan Umrah 2026', $trip->title);
        // Generated rather than supplied, which is its own small contract.
        $this->assertSame('ramadan-umrah-2026', $trip->slug);

        $this->actingAs($admin)->put(route('admin.trips.update', $trip), [
            'title' => 'Ramadan Umrah 2026 — Extended',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-14',
            'status' => 'current',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('current', $trip->fresh()->status);

        $this->actingAs($admin)
            ->delete(route('admin.trips.destroy', $trip))
            ->assertRedirect();

        $this->assertDatabaseMissing('trips', ['id' => $trip->id]);
    }

    /**
     * A trip's slug is its public URL, and the sitemap, the canonical tag and
     * every shared link depend on it holding still.
     *
     * update() regenerated it from the title on every save, so correcting a
     * typo moved the page out from under everything that pointed at it — and
     * overwrote a slug the editor had deliberately set, though the form
     * validates that field for uniqueness.
     */
    public function test_renaming_a_trip_does_not_move_its_public_url(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.trips.store'), [
            'title' => 'Ramdan Umrah 2026',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-10',
            'status' => 'upcoming',
            'is_published' => '1',
        ])->assertSessionHasNoErrors();

        $trip = Trip::sole();
        $this->assertSame('ramdan-umrah-2026', $trip->slug);

        $this->get('/en/trips/ramdan-umrah-2026')->assertOk();

        // Correcting the spelling of the title.
        $this->actingAs($admin)->put(route('admin.trips.update', $trip), [
            'title' => 'Ramadan Umrah 2026',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-10',
            'status' => 'upcoming',
            'is_published' => '1',
        ])->assertSessionHasNoErrors();

        $trip->refresh();

        $this->assertSame('Ramadan Umrah 2026', $trip->title);
        $this->assertSame('ramdan-umrah-2026', $trip->slug, 'Renaming the trip moved its URL.');
        $this->get('/en/trips/ramdan-umrah-2026')->assertOk();
    }

    /** An editor who sets a slug means it, so that one is used. */
    public function test_an_explicit_slug_is_honoured(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.trips.store'), [
            'title' => 'Ramadan Umrah 2026',
            'slug' => 'ramadan-2026',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-10',
            'status' => 'upcoming',
        ])->assertSessionHasNoErrors();

        $trip = Trip::sole();
        $this->assertSame('ramadan-2026', $trip->slug);

        $this->actingAs($admin)->put(route('admin.trips.update', $trip), [
            'title' => 'Ramadan Umrah 2026',
            'slug' => 'ramadan-umrah',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-10',
            'status' => 'upcoming',
        ])->assertSessionHasNoErrors();

        $this->assertSame('ramadan-umrah', $trip->fresh()->slug);
    }

    /**
     * A trip whose end date precedes its start is not a trip. Asserted
     * because the rule exists and nothing had ever exercised it.
     */
    public function test_a_trip_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->admin())->post(route('admin.trips.store'), [
            'title' => 'Impossible',
            'date_start' => '2026-03-10',
            'date_end' => '2026-03-01',
            'status' => 'upcoming',
        ])->assertSessionHasErrors('date_end');

        $this->assertSame(0, Trip::count());
    }

    public function test_media_can_be_created_and_deleted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.media.store'), [
            'type' => 'video',
            'title' => 'Madinah at dawn',
            'video_url' => 'https://www.youtube.com/watch?v=abc123',
            'is_published' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $medium = Media::sole();
        $this->assertSame('Madinah at dawn', $medium->title);

        $this->actingAs($admin)
            ->delete(route('admin.media.destroy', $medium))
            ->assertRedirect();

        $this->assertDatabaseMissing('media', ['id' => $medium->id]);
    }

    public function test_a_hero_banner_can_be_created_updated_and_deleted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.hero-banners.store'), [
            'title' => 'Journeys that stay with you',
            'subtitle' => 'Umrah from the Maldives',
            'overlay_opacity' => 40,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $banner = HeroBanner::sole();
        $this->assertSame('Journeys that stay with you', $banner->title);

        $this->actingAs($admin)->put(route('admin.hero-banners.update', $banner), [
            'title' => 'Journeys that stay with you, always',
            'overlay_opacity' => 50,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(50, $banner->fresh()->overlay_opacity);

        $this->actingAs($admin)
            ->delete(route('admin.hero-banners.destroy', $banner))
            ->assertRedirect();

        $this->assertDatabaseMissing('hero_banners', ['id' => $banner->id]);
    }

    public function test_a_why_section_can_be_updated(): void
    {
        $section = WhySection::create(['title' => 'Why Rihla']);

        $this->actingAs($this->admin())
            ->put(route('admin.why-sections.update', $section), [
                'title' => 'Why travel with Rihla',
                'primary_cta_bg_color' => '#5F498A',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Why travel with Rihla', $section->fresh()->title);
    }

    public function test_a_why_feature_can_be_created_updated_and_deleted(): void
    {
        $admin = $this->admin();
        $section = WhySection::create(['title' => 'Why Rihla']);

        $this->actingAs($admin)->post(route('admin.why-sections.features.store', $section), [
            'why_section_id' => $section->id,
            'title' => 'Licensed by the Ministry',
            'text' => 'Registration C11452023.',
            'sort_order' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $feature = WhyFeature::sole();
        $this->assertSame('Licensed by the Ministry', $feature->title);

        $this->actingAs($admin)->put(route('admin.features.update', $feature), [
            'why_section_id' => $section->id,
            'title' => 'Ministry licensed',
            'sort_order' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Ministry licensed', $feature->fresh()->title);

        $this->actingAs($admin)
            ->delete(route('admin.features.destroy', $feature))
            ->assertRedirect();

        $this->assertDatabaseMissing('why_features', ['id' => $feature->id]);
    }

    /**
     * Roles are only worth having if they hold on the writes too. Reads are
     * covered by RouteSmokeTest; this is the half that changes data.
     */
    public function test_a_role_without_permission_cannot_write(): void
    {
        $reporter = User::factory()->create()->assignRole(Access::REPORTING);
        $trip = Trip::create([
            'title' => 'A Trip',
            'slug' => 'a-trip',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-08',
            'status' => 'upcoming',
            'is_published' => true,
        ]);

        $this->actingAs($reporter)->post(route('admin.trips.store'), [
            'title' => 'Should not exist',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-10',
            'status' => 'upcoming',
        ])->assertForbidden();

        $this->actingAs($reporter)
            ->delete(route('admin.trips.destroy', $trip))
            ->assertForbidden();

        $this->assertDatabaseHas('trips', ['id' => $trip->id]);
        $this->assertSame(1, Trip::count());
    }
}
