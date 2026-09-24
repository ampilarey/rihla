<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Models\Package;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Umrah Plus — §15.3 (Phase 8.7).
 *
 * The owner's correction is the reason this phase exists, so it is the
 * thing most worth a guard: *"Keep holiday packages with Umrah under Umrah
 * services, and holiday packages for locals to local islands in the
 * guesthouses part."* An Umrah with a Turkey extension is an Umrah
 * product. It does not get a tab of its own, and it does not drift into
 * Stays.
 */
class UmrahPlusTest extends TestCase
{
    use RefreshDatabase;

    private function plus(array $attributes = []): Package
    {
        return Package::factory()->create([
            'type' => Package::UMRAH_PLUS,
            'extension_destination' => 'Istanbul, Türkiye',
            'extension_nights' => 3,
            'extension_details' => ['en' => 'Bosphorus tour, breakfast included.'],
            'is_published' => true,
            ...$attributes,
        ]);
    }

    public function test_every_package_that_existed_before_is_an_umrah(): void
    {
        $this->assertTrue(Schema::hasColumn('packages', 'type'));

        // Nothing passes a type, so the column's own default answers — which
        // is what the migration relies on for every row already in there.
        $this->assertSame(Package::UMRAH, Package::factory()->create()->refresh()->type);
    }

    public function test_the_package_page_shows_the_extension(): void
    {
        $package = $this->plus();

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertSee('The extension')
            ->assertSee('Istanbul, Türkiye')
            ->assertSee('3 nights in Istanbul, Türkiye')
            ->assertSee('Bosphorus tour');
    }

    public function test_a_plain_umrah_shows_no_extension_block(): void
    {
        $package = Package::factory()->create(['is_published' => true]);

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertDontSee('The extension');
    }

    /**
     * Proved by planting the half-filled state an admin can actually reach:
     * a package marked Umrah Plus and saved before anybody typed where the
     * extension goes. A heading over an empty box reads as a broken page.
     */
    public function test_an_umrah_plus_with_nowhere_named_shows_no_block(): void
    {
        $package = $this->plus(['extension_destination' => null]);

        $this->assertFalse($package->hasExtension());

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertDontSee('The extension');
    }

    public function test_the_list_can_be_filtered_to_umrah_plus(): void
    {
        $plus = $this->plus(['slug' => 'ramadan-umrah-plus-istanbul']);
        $plain = Package::factory()->create(['slug' => 'shawwal-umrah', 'is_published' => true]);

        $this->get('/en/packages?type=umrah_plus')
            ->assertOk()
            ->assertSee($plus->slug)
            ->assertDontSee($plain->slug);
    }

    /** A hand-edited URL shows packages rather than a validation page. */
    public function test_an_unknown_type_in_the_url_is_ignored(): void
    {
        $package = Package::factory()->create(['is_published' => true]);

        $this->get('/en/packages?type=not-a-type')
            ->assertOk()
            ->assertSee($package->slug);
    }

    // ── Where it sits in the navigation ──────────────────────────────────

    public function test_umrah_plus_is_offered_under_umrah_once_one_is_published(): void
    {
        $this->plus();

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('Umrah Plus', $html);
        // Inside the Umrah group, which is what the owner's correction is
        // about. The Stays group is a separate menu and stays empty here.
        $this->assertStringContainsString('aria-label="Umrah"', $html);
        $this->assertStringNotContainsString('aria-label="Stays"', $html);
    }

    public function test_nothing_advertises_umrah_plus_until_one_is_published(): void
    {
        $this->plus(['is_published' => false]);

        $this->assertStringNotContainsString('Umrah Plus', $this->get('/en')->assertOk()->getContent());
    }

    /**
     * An island holiday is a guesthouse product. It must never appear under
     * the Umrah menu, which is the half of the correction a type column
     * could still get wrong.
     */
    public function test_an_island_holiday_is_not_offered_under_umrah(): void
    {
        $this->plus(['type' => Package::ISLAND_HOLIDAY, 'is_published' => true]);

        $this->assertStringNotContainsString('Umrah Plus', $this->get('/en')->assertOk()->getContent());
    }

    /**
     * Written through the guarded path, not the factory.
     *
     * `Factory::make()` wraps instantiation in `Model::unguarded()`, so a
     * column missing from `$fillable` is set happily in every test and
     * dropped silently by an admin form — the trap `AGENTS.md` names.
     */
    public function test_the_admin_form_saves_the_type_and_its_extension(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Access::SUPER_ADMIN);
        $this->actingAs($user);

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'slug' => 'ramadan-umrah-plus-istanbul',
                'title' => ['en' => 'Ramadan Umrah Plus Istanbul'],
                'summary' => ['en' => 'Ten nights of Umrah, then three in Istanbul.'],
                'type' => Package::UMRAH_PLUS,
                'extension_destination' => 'Istanbul, Türkiye',
                'extension_nights' => 3,
                'extension_details' => ['en' => 'Bosphorus tour, breakfast included.'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $package = Package::where('slug', 'ramadan-umrah-plus-istanbul')->firstOrFail();

        $this->assertSame(Package::UMRAH_PLUS, $package->type);
        $this->assertSame('Istanbul, Türkiye', $package->extension_destination);
        $this->assertSame(3, $package->extension_nights);
        $this->assertTrue($package->hasExtension());
    }
}
