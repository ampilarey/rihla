<?php

namespace Tests\Feature;

use App\Filament\Resources\WhySections\Pages\EditWhySection;
use App\Filament\Resources\WhySections\RelationManagers\FeaturesRelationManager;
use App\Filament\Resources\WhySections\WhySectionResource;
use App\Models\User;
use App\Models\WhyFeature;
use App\Models\WhySection;
use App\Support\Access;
use App\Support\Brand;
use App\Support\Contrast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "why Rihla" section in the staff panel — §9.2.
 *
 * ## Ported from the Blade screen's tests
 *
 * Its routes are gone, so these cases moved here and drive the Filament
 * form, rather than being dropped:
 *
 *  - `AdminWritePathTest::…why_section_can_be_updated`,
 *    `…why_feature_can_be_created_updated_and_deleted`
 *  - `HomepageTranslationTest::…admin_saves_a_why_feature_in_both_languages`,
 *    `…opening_the_why_screen_never_invents_dhivehi`,
 *    `…opening_the_why_screen_twice_makes_one_section`
 *
 * `AuthorizationTest` keeps its case and points it at `/staff`.
 *
 * ## New
 *
 * Every colour is measured on what it sits on, with an empty side resolved
 * to the default the homepage actually renders; and the one fixed text
 * colour the rule relies on is held to the Tailwind config.
 */
class WhySectionAdminTest extends TestCase
{
    use RefreshDatabase;

    private function contentManager(): User
    {
        return User::factory()->create()->assignRole(Access::CONTENT_MANAGER);
    }

    private function section(): WhySection
    {
        return WhySection::create(['title' => ['en' => 'Why Rihla'], 'is_active' => true]);
    }

    private function features(WhySection $section): Testable
    {
        return Livewire::actingAs($this->contentManager())->test(FeaturesRelationManager::class, [
            'ownerRecord' => $section,
            'pageClass' => EditWhySection::class,
        ]);
    }

    // ── The one section ──────────────────────────────────────────────────

    /**
     * Opening the screen used to *create* a section for the panel's locale
     * — and the Dhivehi one it made carried two Thaana sentences nobody
     * wrote. An editor with the panel in Dhivehi silently published
     * machine-generated Dhivehi to the homepage.
     */
    public function test_opening_the_screen_never_invents_dhivehi(): void
    {
        app()->setLocale('dv');

        $this->actingAs($this->contentManager())->get(WhySectionResource::getUrl('index'))->assertRedirect();

        $section = WhySection::sole();

        $this->assertSame('Why Choose Rihla', $section->getTranslation('title', 'en'));
        $this->assertFalse($section->hasTranslation('title', 'dv'), 'The panel wrote Dhivehi nobody typed.');
    }

    public function test_opening_it_twice_makes_one_section(): void
    {
        $manager = $this->contentManager();

        $this->actingAs($manager)->get(WhySectionResource::getUrl('index'))->assertRedirect();
        $this->actingAs($manager)->get(WhySectionResource::getUrl('index'))->assertRedirect();

        $this->assertSame(1, WhySection::count());
    }

    public function test_the_section_can_be_updated(): void
    {
        $section = $this->section();

        Livewire::actingAs($this->contentManager())
            ->test(EditWhySection::class, ['record' => $section->getKey()])
            ->fillForm(['title' => ['en' => 'Why travel with Rihla'], 'primary_cta_bg_color' => Brand::WINE])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Why travel with Rihla', $section->fresh()->getTranslation('title', 'en'));
    }

    /** There is one section — the permissions and the resource both say so. */
    public function test_there_is_nothing_to_create(): void
    {
        $this->assertFalse(WhySectionResource::canCreate());
        $this->assertArrayNotHasKey('create', WhySectionResource::getPages());
    }

    public function test_the_old_addresses_forward_to_the_new_one(): void
    {
        $manager = $this->contentManager();
        $feature = WhyFeature::create(['why_section_id' => $this->section()->id, 'title' => ['en' => 'Licensed'], 'sort_order' => 0]);

        foreach (['/admin/why-sections', '/admin/why-sections/1/edit', "/admin/features/{$feature->id}/edit"] as $old) {
            $this->actingAs($manager)->get($old)->assertRedirect(WhySectionResource::getUrl('index'));
        }
    }

    // ── The cards ────────────────────────────────────────────────────────

    public function test_a_card_can_be_created_updated_and_deleted(): void
    {
        $section = $this->section();

        $this->features($section)
            ->callTableAction('create', data: [
                'title' => ['en' => 'Licensed by the Ministry'],
                'text' => ['en' => 'Registration C11452023.'],
                'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $feature = WhyFeature::sole();
        $this->assertSame('Licensed by the Ministry', $feature->getTranslation('title', 'en'));

        $this->features($section)
            ->callTableAction('edit', $feature, data: ['title' => ['en' => 'Ministry licensed'], 'sort_order' => 1])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Ministry licensed', $feature->fresh()->getTranslation('title', 'en'));

        $this->features($section)->callTableAction('delete', $feature);

        $this->assertDatabaseMissing('why_features', ['id' => $feature->getKey()]);
    }

    public function test_a_card_is_saved_in_both_languages(): void
    {
        $this->features($this->section())
            ->callTableAction('create', data: [
                'title' => ['en' => 'Licensed by the Ministry', 'dv' => 'ލައިސަންސް'],
                'text' => ['en' => 'Registration C11452023.', 'dv' => ''],
                'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $feature = WhyFeature::sole();

        $this->assertSame('ލައިސަންސް', $feature->getTranslation('title', 'dv'));
        $this->assertSame('Registration C11452023.', $feature->getTranslation('text', 'en'));
        $this->assertFalse($feature->hasTranslation('text', 'dv'), 'An empty Dhivehi box was stored as a translation.');
    }

    /**
     * Editing one language must not wipe the other.
     *
     * Holds the *behaviour*, not a mechanism. Filament's default fill
     * already carries both languages here, because this version of
     * spatie/translatable returns the whole array from
     * `attributesToArray()` — measured, after an override written on the
     * opposite assumption was removed and this still passed. If a library
     * upgrade changes that, this is what notices.
     */
    public function test_editing_a_card_keeps_its_other_language(): void
    {
        $section = $this->section();
        $feature = WhyFeature::create([
            'why_section_id' => $section->id,
            'title' => ['en' => 'Licensed', 'dv' => 'ލައިސަންސް'],
            'sort_order' => 0,
        ]);

        $this->features($section)
            // Only the English field is touched. If the form did not load
            // the whole {en, dv} array, the Dhivehi would not be in it to
            // save back, and would be wiped.
            ->callTableAction('edit', $feature, data: ['title.en' => 'Licensed by the Ministry'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Licensed by the Ministry', $feature->fresh()->getTranslation('title', 'en'));
        $this->assertSame('ލައިސަންސް', $feature->fresh()->getTranslation('title', 'dv'), 'Editing the English wiped the Dhivehi.');
    }

    // ── Readable, measured on what renders ───────────────────────────────

    /** A white heading on the section's cream background. */
    public function test_a_heading_that_vanishes_into_the_cream_is_refused(): void
    {
        $section = $this->section();

        Livewire::actingAs($this->contentManager())
            ->test(EditWhySection::class, ['record' => $section->getKey()])
            ->fillForm(['title_color' => Brand::WHITE])
            ->call('save')
            ->assertHasFormErrors(['title_color']);
    }

    /**
     * Gold with the words left on their default — which here is white —
     * refused, as on the hero banner. The empty side is resolved to the
     * default `section-why.blade.php` renders, not read as unknown.
     */
    public function test_gold_with_the_default_words_is_refused(): void
    {
        Livewire::actingAs($this->contentManager())
            ->test(EditWhySection::class, ['record' => $this->section()->getKey()])
            ->fillForm(['primary_cta_bg_color' => Brand::GOLD])
            ->call('save')
            ->assertHasFormErrors(['primary_cta_bg_color']);
    }

    public function test_readable_choices_are_saved(): void
    {
        $section = $this->section();

        Livewire::actingAs($this->contentManager())
            ->test(EditWhySection::class, ['record' => $section->getKey()])
            ->fillForm(['title_color' => Brand::WINE, 'primary_cta_bg_color' => Brand::GOLD, 'primary_cta_text_color' => Brand::INK])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Brand::GOLD, $section->fresh()->primary_cta_bg_color);
    }

    /** A dark card with the card's grey words on it reads as empty. */
    public function test_a_card_colour_its_words_disappear_on_is_refused(): void
    {
        $this->assertLessThan(Contrast::AA, Contrast::ratio(FeaturesRelationManager::CARD_BODY_TEXT, Brand::WINE));

        $this->features($this->section())
            ->callTableAction('create', data: [
                'title' => ['en' => 'Licensed'],
                'sort_order' => 0,
                'background_color' => Brand::WINE,
            ])
            ->assertHasTableActionErrors(['background_color']);

        $this->assertSame(0, WhyFeature::count());
    }

    /**
     * The card's body colour is a literal the rule depends on. It is
     * Tailwind's `gray-600`, so it is held to the config and cannot drift
     * from what the card actually prints.
     */
    public function test_the_card_text_colour_matches_the_tailwind_config(): void
    {
        $config = (string) file_get_contents(base_path('tailwind.config.js'));

        $this->assertMatchesRegularExpression(
            "/gray:\\s*\\{[^}]*600:\\s*'".preg_quote(FeaturesRelationManager::CARD_BODY_TEXT, '/')."'/is",
            $config,
            'FeaturesRelationManager::CARD_BODY_TEXT no longer matches gray-600 in tailwind.config.js.',
        );
    }

    // ── A replaced picture does not stay on the disk ─────────────────────

    public function test_a_replaced_section_picture_is_deleted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('why/old.jpg', 'old');
        Storage::disk('public')->put('why/new.jpg', 'new');

        $section = WhySection::create(['title' => ['en' => 'Why Rihla'], 'image_path' => 'why/old.jpg']);
        $section->update(['image_path' => 'why/new.jpg']);

        Storage::disk('public')->assertMissing('why/old.jpg');
        Storage::disk('public')->assertExists('why/new.jpg');
    }

    public function test_a_deleted_cards_picture_is_deleted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('why/features/card.jpg', 'card');

        $feature = WhyFeature::create([
            'why_section_id' => $this->section()->id,
            'title' => ['en' => 'Licensed'],
            'sort_order' => 0,
            'image_path' => 'why/features/card.jpg',
        ]);

        $feature->delete();

        Storage::disk('public')->assertMissing('why/features/card.jpg');
    }
}
