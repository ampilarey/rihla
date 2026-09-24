<?php

namespace Tests\Feature;

use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\Properties\RelationManagers\RoomTypesRelationManager;
use App\Filament\Resources\Properties\Schemas\PropertyForm;
use App\Http\Middleware\SetLocale;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Stays screens — §15.4 (Phase 9.1).
 *
 * Separate from `StaysFoundationTest`, which covers the data. Everything
 * here goes through the **guarded** path: `Factory::make()` wraps
 * instantiation in `Model::unguarded()`, so a column missing from
 * `$fillable` is set happily in every other test in this repository and
 * dropped silently by a Filament form. That trap is the reason this file
 * exists rather than a few more model assertions.
 */
class StaysAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    // ── Getting in ───────────────────────────────────────────────────────

    public function test_a_super_admin_can_reach_the_properties_list(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(PropertyResource::getUrl('index'))
            ->assertOk();
    }

    /**
     * A property carries what Rihla agreed to pay a partner and what it
     * sells the room for. Editing the website's words is not a reason to
     * see a commercial arrangement with a third party — which is why the
     * Stays permissions are a separate set from `$content` in
     * `Access::matrix()`, and this is the assertion that holds them apart.
     */
    public function test_the_content_manager_cannot_reach_properties(): void
    {
        $editor = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->actingAs($editor)
            ->get(PropertyResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_the_operations_manager_can_reach_properties(): void
    {
        $manager = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);

        $this->actingAs($manager)
            ->get(PropertyResource::getUrl('index'))
            ->assertOk();
    }

    /**
     * Every locale the site serves has a tab on this form.
     *
     * There is no `isset()` guard in `PropertyForm`, on purpose: a locale
     * added to the middleware and forgotten here should break loudly rather
     * than render one tab fewer. This is the assertion that says so before
     * a staff member finds out — a missing Arabic tab looks exactly like an
     * Arabic tab nobody has filled in, and the property would go live with
     * no way to translate it.
     */
    public function test_the_form_offers_a_tab_for_every_locale_the_site_serves(): void
    {
        // Compared as sets. The tab *order* comes from SetLocale::SUPPORTED
        // — the form maps over it — so the order of the label map is not
        // something to pin, and pinning it would fail this test for a
        // reordering that changes nothing.
        $supported = SetLocale::SUPPORTED;
        $labelled = array_keys(PropertyForm::LOCALES);
        sort($supported);
        sort($labelled);

        $this->assertSame(
            $supported,
            $labelled,
            'A locale the site serves has no tab on the property form.',
        );
    }

    public function test_the_property_form_renders_all_three_language_tabs(): void
    {
        $response = Livewire::actingAs($this->superAdmin())->test(CreateProperty::class);

        foreach (PropertyForm::LOCALES as $label) {
            $response->assertSee($label);
        }
    }

    // ── The guarded write path ───────────────────────────────────────────

    /**
     * Every column the form offers, written through the form.
     *
     * The policy fields are the ones that matter most here: they are what
     * the customer is shown and held to, and a `$fillable` omission would
     * drop the partner's negotiated 50% back to the house 30% with no error
     * anywhere.
     */
    public function test_the_admin_form_saves_every_field_it_offers(): void
    {
        $partner = Partner::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(CreateProperty::class)
            ->fillForm([
                'partner_id' => $partner->id,
                'type' => Property::GUESTHOUSE,
                'slug' => 'maafushi-view',
                'island' => 'Maafushi',
                'name' => ['en' => 'Maafushi View'],
                'summary' => ['en' => 'Two minutes from the ferry jetty.'],
                'description' => ['en' => 'A small guesthouse on the village side.'],
                'house_rules' => ['en' => 'Modest dress on the village beach.'],
                'check_in_instructions' => ['en' => 'Ring on arrival at the harbour.'],
                // A simple() repeater keeps its keyed shape in the form
                // state and flattens on save — the same rule
                // PackageAdminTest records. What lands in the column is a
                // flat list, asserted below.
                'amenities' => ['en' => [['item' => 'Air conditioning'], ['item' => 'Breakfast']]],
                'check_in_time' => '14:00',
                'check_out_time' => '11:00',
                'currency' => 'USD',
                'min_nights' => 2,
                'instant_book' => true,
                'is_published' => true,
                'deposit_pct' => 50,
                'balance_days_before' => 30,
                'free_cancel_days' => 7,
                'sort_order' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $property = Property::where('slug', 'maafushi-view')->firstOrFail();

        $this->assertSame($partner->id, $property->partner_id);
        $this->assertSame('Maafushi', $property->island);
        $this->assertSame('Maafushi View', $property->getTranslation('name', 'en'));
        $this->assertSame(['Air conditioning', 'Breakfast'], $property->amenity_list);
        $this->assertSame('USD', $property->currency);
        $this->assertSame(2, $property->min_nights);
        $this->assertTrue($property->instant_book);
        $this->assertTrue($property->is_published);
        $this->assertSame(50, $property->deposit_pct);
        $this->assertSame(30, $property->balance_days_before);
        $this->assertSame(7, $property->free_cancel_days);
        $this->assertSame(3, $property->sort_order);
    }

    /**
     * The form submits all three locale tabs, so a property written only in
     * English arrives carrying `ar => ''` and `dv => ''`. Stored, those are
     * worse than nothing: hasTranslation() answers true for an empty value,
     * so the fallback never fires and the Arabic page renders a blank name.
     */
    public function test_an_untranslated_tab_is_not_stored_as_an_empty_translation(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateProperty::class)
            ->fillForm([
                'partner_id' => Partner::factory()->create()->id,
                'slug' => 'english-only',
                'name' => ['en' => 'English Only', 'ar' => '', 'dv' => ''],
                'summary' => ['en' => 'Nobody has translated this yet.'],
                'currency' => 'USD',
                'deposit_pct' => 30,
                'balance_days_before' => 14,
                'free_cancel_days' => 14,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $property = Property::where('slug', 'english-only')->firstOrFail();

        $this->assertSame(['en'], array_keys($property->getTranslations('name')));

        app()->setLocale('ar');
        $this->assertSame('English Only', $property->name);
    }

    public function test_editing_a_property_keeps_the_languages_it_already_had(): void
    {
        $property = Property::factory()->create([
            'name' => ['en' => 'Fulidhoo Sands', 'ar' => 'رمال فُلِދޫ'],
            'summary' => ['en' => 'By the harbour.'],
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(EditProperty::class, ['record' => $property->getRouteKey()])
            // The Dhivehi box comes back null, because nobody has written
            // one — that is the empty tab, and mutateFormDataBeforeSave
            // drops it rather than storing an empty translation.
            ->assertFormSet(['name' => ['en' => 'Fulidhoo Sands', 'ar' => 'رمال فُلِދޫ', 'dv' => null]])
            ->fillForm(['island' => 'Fulidhoo'])
            ->call('save')
            ->assertHasNoFormErrors();

        $property->refresh();

        $this->assertSame('Fulidhoo', $property->island);
        $this->assertSame('رمال فُلِދޫ', $property->getTranslation('name', 'ar'));
    }

    // ── The rooms inside a property ──────────────────────────────────────

    /**
     * Tested on the relation-manager component directly.
     *
     * `AGENTS.md`: a relation manager loads in its own Livewire request, so
     * the property page's HTML contains no rooms table at all. Fetching the
     * page and asserting 200 would prove nothing about it.
     */
    public function test_the_rooms_table_lists_the_rooms_of_that_property(): void
    {
        $property = Property::factory()->create();
        $mine = RoomType::factory()->create(['property_id' => $property->id, 'name' => ['en' => 'Sea View Double']]);
        $someone_elses = RoomType::factory()->create(['name' => ['en' => 'Garden Twin']]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoomTypesRelationManager::class, [
                'ownerRecord' => $property,
                'pageClass' => EditProperty::class,
            ])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$someone_elses]);
    }

    /**
     * The rate is typed in whole units and stored in minor ones — [R-7].
     *
     * 85 dollars in the box has to land as 8500 cents in the column. Getting
     * this backwards prices a room at 85 cents or at 8,500 dollars, and both
     * look like a plausible number on the screen that wrote them.
     */
    public function test_a_room_rate_typed_in_whole_units_is_stored_in_minor_units(): void
    {
        $property = Property::factory()->create(['currency' => 'USD']);

        Livewire::actingAs($this->superAdmin())
            ->test(RoomTypesRelationManager::class, [
                'ownerRecord' => $property,
                'pageClass' => EditProperty::class,
            ])
            ->callTableAction('create', data: [
                'name' => ['en' => 'Sea View Double'],
                'sleeps' => 2,
                'quantity' => 3,
                'base_rate_minor' => 85,
                'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $room = RoomType::where('property_id', $property->id)->firstOrFail();

        $this->assertSame(8500, $room->base_rate_minor);
        $this->assertSame(3, $room->quantity);
        $this->assertSame('Sea View Double', $room->getTranslation('name', 'en'));
    }

    /** And back out again, so editing a room does not divide it by a hundred. */
    public function test_an_existing_rate_is_shown_back_in_whole_units(): void
    {
        $property = Property::factory()->create(['currency' => 'USD']);
        $room = RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 8500]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoomTypesRelationManager::class, [
                'ownerRecord' => $property,
                'pageClass' => EditProperty::class,
            ])
            ->mountTableAction('edit', $room)
            ->assertTableActionDataSet(['base_rate_minor' => 85]);
    }
}
