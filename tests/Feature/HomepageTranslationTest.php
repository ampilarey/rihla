<?php

namespace Tests\Feature;

use App\Models\HeroBanner;
use App\Models\User;
use App\Models\WhyFeature;
use App\Models\WhySection;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The homepage blocks, in both languages, on one row each.
 *
 * `hero_banners` and `why_sections` each carried a `locale` column with one
 * row per language, and `why_features` — the three cards under "Why Choose
 * Rihla" — had no translation mechanism at all. A Dhivehi why-section meant a
 * second section with its own three features, related to the English three by
 * nothing: changing a card's icon or its order meant doing it twice.
 *
 * And the homepage filtered banners by locale, so a slot with no Dhivehi row
 * simply vanished from /dv.
 */
class HomepageTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../database/migrations/2026_09_19_150000_make_the_homepage_cms_translatable.php';

    protected function setUp(): void
    {
        parent::setUp();

        // The homepage caches the why-section for an hour.
        Cache::forget(WhySection::CACHE_KEY);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    public function test_the_locale_columns_are_gone(): void
    {
        $this->assertNotContains('locale', Schema::getColumnListing('hero_banners'));
        $this->assertNotContains('locale', Schema::getColumnListing('why_sections'));
    }

    /**
     * The merge, against the two-row shape it had to read. No environment has
     * it any more — the Dhivehi rows were deleted as machine-generated — so a
     * test is the only place that case exists.
     */
    public function test_the_migration_merges_the_two_locale_rows(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => true])
            ->assertSuccessful();

        $bannerBase = [
            'is_active' => true, 'overlay_opacity' => 40,
            'created_at' => now(), 'updated_at' => now(),
            'subtitle' => null, 'primary_cta_text' => null, 'secondary_cta_text' => null,
            'image_path' => null,
        ];

        DB::table('hero_banners')->insert([
            array_merge($bannerBase, [
                'sort_order' => 0, 'locale' => 'en', 'title' => 'Umrah 2026',
                'subtitle' => 'From Malé', 'image_path' => 'hero/one.webp',
            ]),
            array_merge($bannerBase, [
                'sort_order' => 0, 'locale' => 'dv', 'title' => 'ޢުމްރާ ٢٠٢٦',
            ]),
            array_merge($bannerBase, ['sort_order' => 1, 'locale' => 'en', 'title' => 'Ramadan']),
        ]);

        $sectionBase = ['is_active' => true, 'created_at' => now(), 'updated_at' => now(), 'subtitle' => null];
        $englishId = DB::table('why_sections')->insertGetId(
            array_merge($sectionBase, ['locale' => 'en', 'title' => 'Why Choose Rihla']),
        );
        $dhivehiId = DB::table('why_sections')->insertGetId(
            array_merge($sectionBase, ['locale' => 'dv', 'title' => 'ކީއްވެ ރިހްލަ']),
        );

        $featureBase = [
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            'text' => null, 'link_text' => null, 'icon' => null,
        ];
        DB::table('why_features')->insert([
            array_merge($featureBase, [
                'why_section_id' => $englishId, 'sort_order' => 0,
                'title' => 'Trusted Guides', 'text' => 'Maldivian group leaders.', 'icon' => '🧭',
            ]),
            array_merge($featureBase, [
                'why_section_id' => $dhivehiId, 'sort_order' => 0,
                'title' => 'އިތުބާރު', 'text' => 'ދިވެހި ގްރޫޕް ލީޑަރުން',
            ]),
            // No English card in slot 1 to fold this into.
            array_merge($featureBase, [
                'why_section_id' => $dhivehiId, 'sort_order' => 1, 'title' => 'ނުވާ ކާޑު',
            ]),
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION, '--realpath' => true])
            ->assertSuccessful();

        $this->assertSame(2, HeroBanner::count(), 'The two rows in slot 0 did not become one.');

        $first = HeroBanner::where('sort_order', 0)->sole();
        $this->assertSame('Umrah 2026', $first->getTranslation('title', 'en'));
        $this->assertSame('ޢުމްރާ ٢٠٢٦', $first->getTranslation('title', 'dv'));
        // The English row's image is the banner's, not one language's.
        $this->assertSame('hero/one.webp', $first->image_path);

        $this->assertSame(1, WhySection::count(), 'The two sections did not become one.');

        $section = WhySection::sole();
        $this->assertSame('Why Choose Rihla', $section->getTranslation('title', 'en'));
        $this->assertSame('ކީއްވެ ރިހްލަ', $section->getTranslation('title', 'dv'));
        $this->assertSame($englishId, $section->id, 'The English row should keep its id.');

        $card = WhyFeature::where('sort_order', 0)->sole();
        $this->assertSame('Trusted Guides', $card->getTranslation('title', 'en'));
        $this->assertSame('އިތުބާރު', $card->getTranslation('title', 'dv'));
        $this->assertSame('🧭', $card->icon, 'The English card keeps its icon.');

        // A Dhivehi card with no English counterpart is not dropped, and does
        // not silently become a fourth card on the homepage either.
        $orphan = WhyFeature::where('sort_order', 1)->sole();
        $this->assertSame($section->id, $orphan->why_section_id);
        $this->assertFalse($orphan->is_active);
    }

    public function test_a_banner_shows_in_both_languages(): void
    {
        HeroBanner::create([
            'title' => ['en' => 'Umrah 2026', 'dv' => 'ޢުމްރާ ٢٠٢٦'],
            'is_active' => true,
        ]);

        $this->get('/en')->assertOk()->assertSee('Umrah 2026');
        $this->get('/dv')->assertOk()->assertSee('ޢުމްރާ ٢٠٢٦', false);
    }

    /**
     * The defect this replaces: an untranslated banner used to disappear from
     * /dv entirely, because the query filtered by locale and there was no
     * Dhivehi row for the slot.
     */
    public function test_an_untranslated_banner_still_shows_in_dhivehi(): void
    {
        HeroBanner::create(['title' => ['en' => 'Umrah 2026'], 'is_active' => true]);

        $this->get('/dv')->assertOk()->assertSee('Umrah 2026');
    }

    /** The fallback is per field: the Dhivehi that exists, English for the rest. */
    public function test_the_why_block_falls_back_field_by_field(): void
    {
        $section = WhySection::create([
            'title' => ['en' => 'Why Choose Rihla', 'dv' => 'ކީއްވެ ރިހްލަ'],
            'subtitle' => ['en' => 'Maldivian pilgrims, looked after.'],
            'is_active' => true,
        ]);

        WhyFeature::create([
            'why_section_id' => $section->id,
            'title' => ['en' => 'Trusted Guides', 'dv' => 'އިތުބާރު'],
            'text' => ['en' => 'A Maldivian group leader travels with you.'],
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->get('/dv')->assertOk()
            ->assertSee('ކީއްވެ ރިހްލަ', false)
            ->assertSee('އިތުބާރު', false)
            ->assertSee('Maldivian pilgrims, looked after.')
            ->assertSee('A Maldivian group leader travels with you.');
    }

    /**
     * A block translated by halves puts English inside an RTL page, and the
     * bidi algorithm moves its full stop to the left of the sentence unless
     * the element says which way its own content runs. That was rare before —
     * a block fell back whole or not at all — and is now the normal case.
     */
    public function test_mixed_language_text_declares_its_own_direction(): void
    {
        $section = WhySection::create([
            'title' => ['en' => 'Why Choose Rihla', 'dv' => 'ކީއްވެ ރިހްލަ'],
            'subtitle' => ['en' => 'Maldivian pilgrims, looked after.'],
            'is_active' => true,
        ]);

        WhyFeature::create([
            'why_section_id' => $section->id,
            'title' => ['en' => 'Trusted Guides'],
            'text' => ['en' => 'A Maldivian group leader travels with you.'],
            'is_active' => true,
            'sort_order' => 0,
        ]);

        HeroBanner::create([
            'title' => ['en' => 'Umrah 2026', 'dv' => 'ޢުމްރާ ٢٠٢٦'],
            'subtitle' => ['en' => 'From Male.'],
            'is_active' => true,
        ]);

        $html = $this->get('/dv')->assertOk()->getContent();

        foreach ([
            'Maldivian pilgrims, looked after.',
            'A Maldivian group leader travels with you.',
            'From Male.',
        ] as $text) {
            $position = strpos($html, e($text));

            $this->assertNotFalse($position, "The page does not contain \"{$text}\".");

            $before = substr($html, 0, $position);
            $tag = substr($before, strrpos($before, '<') ?: 0);

            $this->assertStringContainsString('dir="auto"', $tag, sprintf(
                'The element holding "%s" does not declare its own direction.', $text,
            ));
        }
    }

    public function test_the_admin_saves_a_banner_in_both_languages(): void
    {
        $this->actingAs($this->admin())->post(route('admin.hero-banners.store'), [
            'title' => ['en' => 'Umrah 2026', 'dv' => 'ޢުމްރާ ٢٠٢٦'],
            'subtitle' => ['en' => 'From Malé', 'dv' => ''],
            'overlay_opacity' => 40,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $banner = HeroBanner::sole();

        $this->assertSame('ޢުމްރާ ٢٠٢٦', $banner->getTranslation('title', 'dv'));
        $this->assertFalse($banner->hasTranslation('subtitle', 'dv'));
    }

    public function test_dhivehi_is_never_required_on_a_banner(): void
    {
        $this->actingAs($this->admin())->post(route('admin.hero-banners.store'), [
            'title' => ['en' => 'Umrah 2026', 'dv' => ''],
            'overlay_opacity' => 40,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, HeroBanner::count());
    }

    public function test_english_is_required_on_a_banner(): void
    {
        $this->actingAs($this->admin())->post(route('admin.hero-banners.store'), [
            'title' => ['en' => '', 'dv' => 'ޢުމްރާ'],
            'overlay_opacity' => 40,
        ])->assertSessionHasErrors('title.en');

        $this->assertSame(0, HeroBanner::count());
    }

    public function test_the_admin_saves_a_why_feature_in_both_languages(): void
    {
        $section = WhySection::create(['title' => ['en' => 'Why Rihla'], 'is_active' => true]);

        $this->actingAs($this->admin())->post(route('admin.why-sections.features.store', $section), [
            'why_section_id' => $section->id,
            'title' => ['en' => 'Licensed by the Ministry', 'dv' => 'ލައިސަންސް'],
            'text' => ['en' => 'Registration C11452023.'],
            'sort_order' => 0,
        ])->assertSessionHasNoErrors();

        $feature = WhyFeature::sole();

        $this->assertSame('ލައިސަންސް', $feature->getTranslation('title', 'dv'));
        $this->assertSame('Registration C11452023.', $feature->getTranslation('text', 'en'));
    }

    /**
     * Opening this screen used to *create* a section when none existed for the
     * panel's locale — and the Dhivehi one it created carried two hard-coded
     * Thaana sentences nobody had written. An editor with the panel in
     * Dhivehi silently published machine-generated Dhivehi to the homepage.
     */
    public function test_opening_the_why_screen_never_invents_dhivehi(): void
    {
        app()->setLocale('dv');

        $this->actingAs($this->admin())->get(route('admin.why-sections.index'))->assertRedirect();

        $section = WhySection::sole();

        $this->assertSame('Why Choose Rihla', $section->getTranslation('title', 'en'));
        $this->assertFalse($section->hasTranslation('title', 'dv'),
            'The panel wrote Dhivehi nobody typed.');
    }

    /** And it opens the one that exists rather than adding another. */
    public function test_opening_the_why_screen_twice_makes_one_section(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.why-sections.index'))->assertRedirect();
        $this->actingAs($admin)->get(route('admin.why-sections.index'))->assertRedirect();

        $this->assertSame(1, WhySection::count());
    }
}
