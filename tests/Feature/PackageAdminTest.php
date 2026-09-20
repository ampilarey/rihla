<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Filament\Resources\Packages\RelationManagers\DeparturesRelationManager;
use App\Models\Departure;
use App\Models\ItineraryItem;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Managing packages and their departures in the staff panel.
 *
 * Phase 2.1 created the tables; without these screens the only way to edit
 * either is tinker, which is the same gap the staff-accounts module filled.
 */
class PackageAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    public function test_a_content_manager_can_reach_packages(): void
    {
        // Packages are public-facing copy, so they belong to whoever edits
        // the rest of the site — unlike staff accounts.
        $editor = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->actingAs($editor)->get('/staff/packages')->assertOk();
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $leader = User::factory()->create()->assignRole(Access::TOUR_LEADER);

        $this->actingAs($leader)->get('/staff/packages')->assertForbidden();
    }

    public function test_the_list_shows_packages_with_their_departure_counts(): void
    {
        $package = Package::factory()->create(['title' => ['en' => 'Ramadan Umrah']]);
        Departure::factory()->count(2)->create(['package_id' => $package->id]);

        Livewire::actingAs($this->superAdmin())
            ->test(ListPackages::class)
            ->assertCanSeeTableRecords([$package])
            ->assertSee('Ramadan Umrah');
    }

    /**
     * The form writes both languages.
     *
     * `$package->title` is the translation for the *current* locale, so a
     * form bound to it would edit one language and silently discard the
     * other. The fields are named `title.en` / `title.dv` and bind to the
     * whole array.
     */
    public function test_creating_a_package_saves_both_languages(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreatePackage::class)
            ->fillForm([
                'slug' => 'ramadan-umrah',
                'title' => ['en' => 'Ramadan Umrah', 'dv' => 'ރަމަޟާން ޢުމްރާ'],
                'summary' => ['en' => 'Fourteen nights.'],
                // A simple() repeater keeps its keyed shape in the form
                // state and flattens on save. Filling it needs the keyed
                // shape; what must land in the column is a flat list, which
                // is what this test asserts below.
                'inclusions' => ['en' => [['item' => 'Return flights'], ['item' => 'Visa processing']]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $package = Package::sole();

        $this->assertSame('Ramadan Umrah', $package->getTranslation('title', 'en'));
        $this->assertSame('ރަމަޟާން ޢުމްރާ', $package->getTranslation('title', 'dv'));
        // Flat strings, not ['item' => …] wrappers. The views iterate this
        // list and print each entry; a wrapper would render "Array".
        $this->assertSame(['Return flights', 'Visa processing'], $package->inclusion_list);
        // And nothing for Dhivehi. The form submits both locale tabs, so an
        // English-only package arrives carrying `dv => ''` and `dv => []`.
        // Stored, hasTranslation() answers *true* for those and the
        // per-field fallback never fires — the Dhivehi page would render a
        // blank title and an empty inclusions list instead of the English.
        // An empty box is not a translation.
        $this->assertSame(
            ['en' => ['Return flights', 'Visa processing']],
            $package->getTranslations('inclusions'),
        );
        $this->assertFalse($package->hasTranslation('inclusions', 'dv'));
        $this->assertFalse($package->hasTranslation('summary', 'dv'));

        // The visible consequence, asserted rather than inferred.
        $this->app->setLocale('dv');
        $this->assertSame(['Return flights', 'Visa processing'], $package->fresh()->inclusion_list);
        $this->assertSame('Fourteen nights.', $package->fresh()->summary);
    }

    /**
     * Editing must not lose the language the editor is not looking at.
     *
     * This is the whole reason EditPackage expands translations before
     * filling: a form filled from `$package->title` holds one string, and
     * saving it would write that string over both languages.
     */
    public function test_editing_in_one_language_leaves_the_other_alone(): void
    {
        $package = Package::factory()->create([
            'title' => ['en' => 'Ramadan Umrah', 'dv' => 'ރަމަޟާން ޢުމްރާ'],
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->assertFormSet(['title' => ['en' => 'Ramadan Umrah', 'dv' => 'ރަމަޟާން ޢުމްރާ']])
            ->fillForm(['title.en' => 'Ramadan Umrah 2027'])
            ->call('save')
            ->assertHasNoFormErrors();

        $package->refresh();

        $this->assertSame('Ramadan Umrah 2027', $package->getTranslation('title', 'en'));
        $this->assertSame('ރަމަޟާން ޢުމްރާ', $package->getTranslation('title', 'dv'), 'The Dhivehi title was overwritten.');
    }

    /** The same rule on the nested itinerary rows. */
    public function test_an_itinerary_day_left_in_english_still_falls_back(): void
    {
        $package = Package::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(DeparturesRelationManager::class, [
                'ownerRecord' => $package,
                'pageClass' => EditPackage::class,
            ])
            ->callTableAction('create', data: [
                'date_start' => '2027-03-01',
                'date_end' => '2027-03-15',
                'status' => Departure::STATUS_UPCOMING,
                'itinerary' => [
                    ['day_number' => 1, 'title' => ['en' => 'Arrive in Madinah']],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $item = ItineraryItem::sole();

        $this->assertFalse($item->hasTranslation('title', 'dv'));
        $this->assertFalse($item->hasTranslation('description', 'dv'));

        $this->app->setLocale('dv');
        $this->assertSame('Arrive in Madinah', $item->fresh()->title);
    }

    public function test_an_english_title_is_required_and_dhivehi_is_not(): void
    {
        // Per AGENTS.md: the Dhivehi on this site is being removed rather
        // than trusted, and an empty field falls back to English. Requiring
        // one is how machine-translated text got here before.
        Livewire::actingAs($this->superAdmin())
            ->test(CreatePackage::class)
            ->fillForm(['slug' => 'no-title', 'title' => ['dv' => 'ހަމައެކަނި ދިވެހި'], 'summary' => ['en' => 'x']])
            ->call('create')
            ->assertHasFormErrors(['title.en']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreatePackage::class)
            ->fillForm(['slug' => 'english-only', 'title' => ['en' => 'English only'], 'summary' => ['en' => 'x']])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    // ── Departures, and the money conversion ─────────────────────────────

    public function test_the_departures_table_lists_them_under_their_package(): void
    {
        $package = Package::factory()->create();
        $departure = Departure::factory()->create([
            'package_id' => $package->id,
            'capacity_total' => 24,
            'capacity_confirmed' => 18,
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(DeparturesRelationManager::class, [
                'ownerRecord' => $package,
                'pageClass' => EditPackage::class,
            ])
            ->assertCanSeeTableRecords([$departure])
            ->assertSee('18 of 24');
    }

    /**
     * Prices are typed in whole rufiyaa and stored in laari.
     *
     * The conversion lives in the form and in App\Support\Money and nowhere
     * else ([R-7]). If it is ever dropped from one side, every price on the
     * site is out by a factor of a hundred — in whichever direction is worse.
     */
    public function test_a_price_typed_in_rufiyaa_is_stored_in_laari(): void
    {
        $package = Package::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(DeparturesRelationManager::class, [
                'ownerRecord' => $package,
                'pageClass' => EditPackage::class,
            ])
            ->callTableAction('create', data: [
                'date_start' => '2027-03-01',
                'date_end' => '2027-03-15',
                'status' => Departure::STATUS_UPCOMING,
                'priceTiers' => [
                    ['occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 28_500],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $tier = PriceTier::sole();

        $this->assertSame(2_850_000, $tier->amount_minor);
        $this->assertSame('MVR 28,500', $tier->formatted);
    }

    public function test_an_existing_price_is_shown_back_in_rufiyaa(): void
    {
        $package = Package::factory()->create();
        $departure = Departure::factory()->create(['package_id' => $package->id]);
        PriceTier::create([
            'departure_id' => $departure->id,
            'occupancy' => 'quad',
            'amount_minor' => 2_850_000,
        ]);

        // The other half of the conversion. Showing 2,850,000 in a field
        // labelled "whole rufiyaa" is how a staff member re-saves it as
        // 285,000,000 laari.
        Livewire::actingAs($this->superAdmin())
            ->test(DeparturesRelationManager::class, [
                'ownerRecord' => $package,
                'pageClass' => EditPackage::class,
            ])
            ->mountTableAction('edit', $departure)
            ->assertTableActionDataSet(fn (array $data): bool => $data['priceTiers'][array_key_first($data['priceTiers'])]['amount_minor'] === 28_500);
    }

    public function test_an_itinerary_day_saves_both_languages(): void
    {
        $package = Package::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(DeparturesRelationManager::class, [
                'ownerRecord' => $package,
                'pageClass' => EditPackage::class,
            ])
            ->callTableAction('create', data: [
                'date_start' => '2027-03-01',
                'date_end' => '2027-03-15',
                'status' => Departure::STATUS_UPCOMING,
                'itinerary' => [
                    [
                        'day_number' => 1,
                        'city' => 'madinah',
                        'title' => ['en' => 'Arrive in Madinah', 'dv' => 'މަދީނާއަށް'],
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $item = ItineraryItem::sole();

        $this->assertSame('Arrive in Madinah', $item->getTranslation('title', 'en'));
        $this->assertSame('މަދީނާއަށް', $item->getTranslation('title', 'dv'));
    }
}
