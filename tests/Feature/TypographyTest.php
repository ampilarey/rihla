<?php

namespace Tests\Feature;

use App\Models\GuideStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fonts fail quietly.
 *
 * The site asked Google Fonts for "Faruma", which does not exist there: two
 * requests per page load, both returning 400, one of them from an @import
 * inside a <style> block that blocks rendering until it fails. A second
 *
 * @font-face pointed at a hand-written fonts.gstatic.com URL returning 404.
 * Dhivehi rendered in whatever the browser had, while the real font sat
 * unused in public/fonts. Nothing errored, so nothing was noticed.
 */
class TypographyTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return file_get_contents(resource_path('css/dhivehi-fonts.css'))
            .file_get_contents(resource_path('css/app.css'));
    }

    private function layout(): string
    {
        return file_get_contents(resource_path('views/layouts/app.blade.php'));
    }

    /**
     * The lockup's tracking is solved from the face's metrics, not chosen.
     *
     * The mark and both name lines are all one width by construction — that
     * is the whole lockup. Each line reaches that width by letter-spacing,
     * so the two numbers belong to Montserrat specifically: swap the face and
     * leave them, and the name silently stops lining up with the boat.
     * They were 0.58em and 0.21em when the face was Inter.
     */
    public function test_the_wordmark_carries_its_own_face_and_the_tracking_that_face_needs(): void
    {
        $logo = file_get_contents(resource_path('views/components/brand-logo.blade.php'));

        $this->assertSame(2, substr_count($logo, 'font-wordmark'),
            'Both name lines must name the wordmark family, or they render in two different faces.');

        $this->assertStringContainsString('letter-spacing: 0.7114em', $logo,
            'RIHLA is not tracked to the mark width for Montserrat.');

        $this->assertStringContainsString('letter-spacing: 0.2347em', $logo,
            'TRAVELS is not tracked to the mark width for Montserrat.');

        $this->assertFileExists(public_path('fonts/montserrat-wordmark.woff2'),
            'The wordmark face is not on disk.');

        $this->assertLessThan(8192, filesize(public_path('fonts/montserrat-wordmark.woff2')),
            'The wordmark face is subset to nine letters; anything this large is the full family.');

        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("url('/fonts/montserrat-wordmark.woff2') format('woff2')", $css);
        $this->assertStringContainsString('unicode-range: U+0052', $css,
            'Without a unicode-range a nine-glyph face gets asked for the whole page.');

        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('montserrat-wordmark.woff2', $layout,
            'The wordmark face is not preloaded, so the lockup reflows when it lands.');
    }

    public function test_the_thaana_font_is_served_from_this_origin(): void
    {
        $this->assertFileExists(public_path('fonts/A_faruma.woff2'));
        $this->assertFileExists(public_path('fonts/A_faruma.ttf'));

        $this->assertStringContainsString("url('/fonts/A_faruma.woff2') format('woff2')", $this->css());
    }

    /**
     * WOFF2 must be listed before the TTF: browsers take the first format
     * they support, so the order is what decides whether 12 KB or 28 KB goes
     * over the wire.
     */
    public function test_woff2_is_offered_before_the_truetype_fallback(): void
    {
        $css = $this->css();

        $this->assertLessThan(
            strpos($css, 'A_faruma.ttf'),
            strpos($css, 'A_faruma.woff2'),
            'The TrueType fallback is listed before WOFF2, so browsers will take the larger file.',
        );

        $this->assertLessThan(
            filesize(public_path('fonts/A_faruma.ttf')),
            filesize(public_path('fonts/A_faruma.woff2')),
        );
    }

    public function test_no_stylesheet_requests_a_font_that_does_not_exist(): void
    {
        $sources = $this->css().$this->get('/dv/guide')->assertOk()->getContent();

        // Faruma is a Maldivian font and has never been on Google Fonts.
        $this->assertStringNotContainsString('family=Faruma', $sources);
        $this->assertStringNotContainsString('fonts.gstatic.com/s/faruma', $sources);
    }

    /**
     * An @import inside a <style> block is fetched before the browser paints,
     * so a slow or failing one delays first paint for every visitor.
     */
    public function test_the_layout_does_not_block_rendering_on_an_at_import(): void
    {
        $this->assertStringNotContainsString('@import url(', $this->get('/en')->assertOk()->getContent());
    }

    /**
     * Arabic families carry no Thaana. Loading two of them as a "fallback"
     * for Dhivehi cost sixteen font weights per page and could never have
     * rendered a single Dhivehi character.
     */
    public function test_unused_font_families_are_not_loaded(): void
    {
        // Asserted against the rendered page, not the template: a Blade
        // comment explaining what was removed is not a font request.
        $html = $this->get('/en/guide')->assertOk()->getContent();

        $this->assertStringNotContainsString('Tajawal', $html);

        // Cairo stays, but for Arabic and at two weights rather than nine.
        $this->assertStringContainsString('family=Cairo:wght@400;700', $html);
    }

    /**
     * The face holds 50 Thaana glyphs and 3 Latin ones. Without the range it
     * would supply those three in place of Inter's, and would be downloaded
     * on English pages that never show a Thaana character.
     */
    public function test_the_thaana_face_is_restricted_to_thaana(): void
    {
        $this->assertMatchesRegularExpression(
            '/unicode-range:\s*U\+0780-07BF/i',
            $this->css(),
        );
    }

    public function test_the_font_is_preloaded_only_where_it_is_used(): void
    {
        $this->get('/dv/guide')
            ->assertOk()
            ->assertSee('rel="preload"', false)
            ->assertSee('A_faruma.woff2', false);

        $this->get('/en/guide')
            ->assertOk()
            ->assertDontSee('A_faruma.woff2', false);
    }

    /** Debug styling that forces red 24px text had shipped in the production bundle. */
    public function test_no_debug_styles_ship(): void
    {
        $this->assertStringNotContainsString('font-test-afruama', $this->css());
        $this->assertStringNotContainsString('color: red', $this->css());
    }

    /**
     * Neither Inter nor the Thaana face covers Arabic, so a supplication had
     * been rendering in whatever the device happened to have.
     */
    public function test_dua_text_is_set_in_an_arabic_face(): void
    {
        GuideStep::factory()->create([
            'step_number' => 1,
            'dua_text' => 'Labbayka Allahumma labbayk',
            'is_published' => true,
        ]);

        $this->get('/en/guide')
            ->assertOk()
            ->assertSee('font-arabic', false)
            ->assertSee('lang="ar"', false);
    }

    public function test_the_pre_rebrand_blue_is_gone_from_the_pdf(): void
    {
        $pdf = file_get_contents(resource_path('views/pdf/guide.blade.php'));

        $this->assertStringNotContainsString('#f0f8ff', $pdf);
    }
}
