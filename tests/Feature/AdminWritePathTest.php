<?php

namespace Tests\Feature;

use App\Filament\Resources\Media\Pages\CreateMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Media;
use App\Models\Trip;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

        // Through the staff panel's form, which replaced the Blade one (§9.2).
        Livewire::actingAs($admin)
            ->test(CreateMedia::class)
            ->fillForm([
                'type' => 'video',
                'title' => ['en' => 'Madinah at dawn'],
                'video_url' => 'https://www.youtube.com/watch?v=abc123',
                'is_published' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $medium = Media::sole();
        $this->assertSame('Madinah at dawn', $medium->title);

        Livewire::actingAs($admin)
            ->test(ListMedia::class)
            ->callTableAction('delete', $medium);

        $this->assertDatabaseMissing('media', ['id' => $medium->id]);
    }

    // Hero banners and the "why Rihla" section moved to the staff panel
    // (§9.2). Their write paths are now driven through the Filament forms in
    // HeroBannerAdminTest and WhySectionAdminTest.

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
