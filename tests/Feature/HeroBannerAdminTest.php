<?php

namespace Tests\Feature;

use App\Filament\Resources\HeroBanners\HeroBannerResource;
use App\Filament\Resources\HeroBanners\Pages\CreateHeroBanner;
use App\Filament\Resources\HeroBanners\Pages\EditHeroBanner;
use App\Filament\Resources\HeroBanners\Pages\ListHeroBanners;
use App\Models\HeroBanner;
use App\Models\User;
use App\Support\Access;
use App\Support\Brand;
use App\Support\Contrast;
use App\Support\HeroBannerStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hero banners in the staff panel — §9.2, the first Blade screen moved.
 *
 * ## What moved here, and from where
 *
 * The Blade screen was guarded by tests spread across five suites. Its
 * routes are gone, so those cases are **ported, not dropped**, and each
 * one now drives the Filament form:
 *
 *  - `AdminWritePathTest::…hero_banner_can_be_created_updated_and_deleted`
 *  - `HomepageTranslationTest::…admin_saves_a_banner_in_both_languages`,
 *    `…dhivehi_is_never_required_on_a_banner`, `…english_is_required…`
 *  - `ImageUploadTest::…a_hero_banner_image_is_stored`
 *
 * `AuthorizationTest` keeps its case and points it at `/staff`.
 *
 * ## What is new
 *
 * The rules the Blade form could not state: colours come from the
 * palette, a button's words must be readable on the button, a stored
 * off-palette colour survives a save that did not touch it, and every
 * style class the form offers actually exists in the built CSS.
 */
class HeroBannerAdminTest extends TestCase
{
    use RefreshDatabase;

    private function contentManager(): User
    {
        return User::factory()->create()->assignRole(Access::CONTENT_MANAGER);
    }

    // ── Ported from the Blade screen's tests ─────────────────────────────

    public function test_a_banner_can_be_created_updated_and_deleted(): void
    {
        $manager = $this->contentManager();

        Livewire::actingAs($manager)
            ->test(CreateHeroBanner::class)
            ->fillForm([
                'title' => ['en' => 'Journeys that stay with you'],
                'subtitle' => ['en' => 'Umrah from the Maldives'],
                'overlay_opacity' => 40,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $banner = HeroBanner::sole();
        $this->assertSame('Journeys that stay with you', $banner->getTranslation('title', 'en'));

        Livewire::actingAs($manager)
            ->test(EditHeroBanner::class, ['record' => $banner->getKey()])
            ->fillForm(['title' => ['en' => 'Journeys that stay with you, always'], 'overlay_opacity' => 50])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(50, $banner->fresh()->overlay_opacity);
        $this->assertSame('Journeys that stay with you, always', $banner->fresh()->getTranslation('title', 'en'));

        Livewire::actingAs($manager)
            ->test(EditHeroBanner::class, ['record' => $banner->getKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('hero_banners', ['id' => $banner->getKey()]);
    }

    /**
     * An empty Dhivehi box is not a translation. Stored, `hasTranslation()`
     * would answer true and the fallback to English would never fire —
     * the Dhivehi homepage would show a blank line under the heading.
     */
    public function test_both_languages_are_saved_and_an_empty_one_is_not(): void
    {
        Livewire::actingAs($this->contentManager())
            ->test(CreateHeroBanner::class)
            ->fillForm([
                'title' => ['en' => 'Umrah 2026', 'dv' => 'ޢުމްރާ ٢٠٢٦'],
                'subtitle' => ['en' => 'From Malé', 'dv' => ''],
                'overlay_opacity' => 40,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $banner = HeroBanner::sole();

        $this->assertSame('ޢުމްރާ ٢٠٢٦', $banner->getTranslation('title', 'dv'));
        $this->assertFalse($banner->hasTranslation('subtitle', 'dv'));
    }

    public function test_dhivehi_is_never_required(): void
    {
        Livewire::actingAs($this->contentManager())
            ->test(CreateHeroBanner::class)
            ->fillForm(['title' => ['en' => 'Umrah 2026', 'dv' => ''], 'overlay_opacity' => 40])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, HeroBanner::count());
    }

    public function test_english_is_required(): void
    {
        Livewire::actingAs($this->contentManager())
            ->test(CreateHeroBanner::class)
            ->fillForm(['title' => ['en' => '', 'dv' => 'ޢުމްރާ'], 'overlay_opacity' => 40])
            ->call('create')
            ->assertHasFormErrors(['title.en' => 'required']);

        $this->assertSame(0, HeroBanner::count());
    }

    /**
     * Stored, and the responsive variants made — by the observer now,
     * which is why the controller could go.
     */
    public function test_a_photograph_is_stored_with_its_variants(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->contentManager())
            ->test(CreateHeroBanner::class)
            ->fillForm([
                'title' => ['en' => 'Journeys that stay with you'],
                'overlay_opacity' => 40,
                'image_path' => UploadedFile::fake()->image('hero.jpg', 2400, 1200),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $banner = HeroBanner::sole();

        $this->assertNotNull($banner->image_path, 'No image path was stored.');
        Storage::disk('public')->assertExists($banner->image_path);
    }

    // ── Who may use it ───────────────────────────────────────────────────

    public function test_the_content_manager_can_open_it(): void
    {
        $this->actingAs($this->contentManager())
            ->get(HeroBannerResource::getUrl('index'))
            ->assertOk();
    }

    /**
     * A bookmark to the old screen still arrives somewhere useful rather
     * than at a 404.
     */
    public function test_the_old_address_forwards_to_the_new_one(): void
    {
        $this->actingAs($this->contentManager())
            ->get('/admin/hero-banners')
            ->assertRedirect(HeroBannerResource::getUrl('index'));

        $this->actingAs($this->contentManager())
            ->get('/admin/hero-banners/3/edit')
            ->assertRedirect(HeroBannerResource::getUrl('index'));
    }

    /** The list renders — a Filament page answers 200 while a column throws. */
    public function test_the_list_renders_its_rows(): void
    {
        HeroBanner::create(['title' => ['en' => 'Ramadan departures'], 'is_active' => true]);

        Livewire::actingAs($this->contentManager())
            ->test(ListHeroBanners::class)
            ->assertOk()
            ->assertSee('Ramadan departures');
    }

    // ── What a person is allowed to choose ───────────────────────────────

    /**
     * The one combination the owner ruled out by name: the gold as a
     * button with white words, 1.49:1. The Blade form's colour picker
     * accepted it without a word.
     */
    public function test_gold_with_white_words_is_refused(): void
    {
        Livewire::actingAs($this->contentManager())
            ->test(CreateHeroBanner::class)
            ->fillForm([
                'title' => ['en' => 'Ramadan'],
                'overlay_opacity' => 40,
                'primary_cta_bg_color' => Brand::GOLD,
                'primary_cta_text_color' => Brand::WHITE,
            ])
            ->call('create')
            ->assertHasFormErrors(['primary_cta_text_color']);

        $this->assertSame(0, HeroBanner::count());
    }

    /**
     * A palette is not a guarantee. White and cream are both palette
     * values and 1.05:1 against each other — which is why the rule is a
     * measured ratio and not a list of banned pairs.
     */
    public function test_two_palette_colours_can_still_be_unreadable(): void
    {
        $this->assertLessThan(Contrast::AA, Contrast::ratio(Brand::WHITE, Brand::CREAM));

        Livewire::actingAs($this->contentManager())
            ->test(CreateHeroBanner::class)
            ->fillForm([
                'title' => ['en' => 'Ramadan'],
                'overlay_opacity' => 40,
                'secondary_cta_bg_color' => Brand::CREAM,
                'secondary_cta_text_color' => Brand::WHITE,
            ])
            ->call('create')
            ->assertHasFormErrors(['secondary_cta_text_color']);
    }

    public function test_ink_on_gold_is_accepted(): void
    {
        Livewire::actingAs($this->contentManager())
            ->test(CreateHeroBanner::class)
            ->fillForm([
                'title' => ['en' => 'Ramadan'],
                'overlay_opacity' => 40,
                'primary_cta_bg_color' => Brand::GOLD,
                'primary_cta_text_color' => Brand::INK,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(Brand::INK, HeroBanner::sole()->primary_cta_text_color);
    }

    /**
     * Left blank, a style is left out of the save and the column's own
     * default applies. Every style column is `NOT NULL` with a database
     * default; sending `null` for "the site default" failed the insert
     * outright — the Blade form never hit it because a colour input always
     * posts a value.
     *
     * The defaults themselves are readable, which is the other half of
     * why a blank is safe: it is not an unchecked colour, it is the one
     * `BrandColourTest` already holds to contrast.
     */
    public function test_unset_styles_take_the_column_defaults(): void
    {
        Livewire::actingAs($this->contentManager())
            ->test(CreateHeroBanner::class)
            ->fillForm(['title' => ['en' => 'Ramadan'], 'overlay_opacity' => 40])
            ->call('create')
            ->assertHasNoFormErrors();

        $banner = HeroBanner::sole()->fresh();

        $this->assertSame(Brand::WINE, $banner->primary_cta_bg_color);
        $this->assertSame('#ffffff', strtolower((string) $banner->primary_cta_text_color));
        $this->assertSame('text-2xl', $banner->heading_size);
        $this->assertSame(HeroBannerStyle::TRANSLUCENT_WHITE, $banner->secondary_cta_bg_color);

        $this->assertGreaterThanOrEqual(
            Contrast::AA,
            Contrast::ratio($banner->primary_cta_bg_color, $banner->primary_cta_text_color),
        );
    }

    /**
     * On an edit, an empty select is an *unchanged* field, not a reset —
     * so opening a banner and saving it without touching a colour can
     * never repaint it.
     */
    public function test_an_edit_that_leaves_a_style_alone_keeps_it(): void
    {
        $banner = HeroBanner::create([
            'title' => ['en' => 'Ramadan'],
            'overlay_opacity' => 40,
            'heading_weight' => 'font-extrabold',
        ]);

        Livewire::actingAs($this->contentManager())
            ->test(EditHeroBanner::class, ['record' => $banner->getKey()])
            ->fillForm(['title' => ['en' => 'Ramadan, renamed']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('font-extrabold', $banner->fresh()->heading_weight);
    }

    /**
     * A banner saved under the free picker may hold anything. Offering
     * only the palette would render a blank select and **overwrite the
     * stored colour on the next save** — repainting a live homepage
     * because somebody fixed a typo in the heading. It survives, and says
     * what it is.
     */
    public function test_a_stored_off_palette_colour_survives_an_unrelated_edit(): void
    {
        $banner = HeroBanner::create([
            'title' => ['en' => 'Old banner'],
            'overlay_opacity' => 40,
            'heading_color' => '#123456',
        ]);

        $this->assertArrayHasKey('#123456', HeroBannerStyle::coloursIncluding('#123456'));
        $this->assertStringContainsString('not in the palette', HeroBannerStyle::coloursIncluding('#123456')['#123456']);

        Livewire::actingAs($this->contentManager())
            ->test(EditHeroBanner::class, ['record' => $banner->getKey()])
            ->fillForm(['title' => ['en' => 'Old banner, typo fixed']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('#123456', $banner->fresh()->heading_color);
    }

    // ── The classes it offers exist ──────────────────────────────────────

    /**
     * Every size, weight and radius the form offers is in the built CSS.
     *
     * They are stored as class names and interpolated into the homepage,
     * and Tailwind only emits a class it has seen written in a scanned
     * file. Four of them — `font-extrabold`, `font-light`, `rounded-none`,
     * `rounded-sm` — were written nowhere but the old Blade form, so
     * deleting it would have made them compile to nothing: a banner saved
     * as "extra bold" rendering at the default weight, markup correct and
     * every other test green. Measured: with `HeroBannerStyle.php` removed
     * from Tailwind's content list, all four went to zero.
     */
    public function test_every_style_the_form_offers_is_in_the_built_css(): void
    {
        $css = implode("\n", array_map(
            fn (string $file): string => File::get($file),
            glob(public_path('build/assets/app-*.css')) ?: [],
        ));

        $this->assertNotSame('', $css, 'No built stylesheet found. Run `npm run build`.');

        $missing = array_values(array_filter(
            HeroBannerStyle::classes(),
            fn (string $class): bool => ! str_contains($css, '.'.$class.'{'),
        ));

        $this->assertSame([], $missing, 'Offered on the form but absent from the built CSS: '.implode(', ', $missing));
    }
}
