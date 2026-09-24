<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Arabic as a routable, right-to-left locale — §15.3 (Phase 8.4).
 *
 * This is the shell only: `ar` is reachable, renders right-to-left, and is
 * offered as an hreflang alternate, exactly like Dhivehi. No Arabic copy
 * ships in this phase — every string a page shows still falls back to
 * English, because §15.3 requires every Arabic string to be written or
 * checked by a person who reads Arabic before it merges, and the plan's
 * drafting assistant "may draft and may not publish". Fabricating Arabic
 * here would repeat exactly the mistake `TranslationQualityTest` exists to
 * catch for Dhivehi. Real Arabic content is a follow-up once a translator
 * is in the loop.
 */
class ArabicLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_arabic_is_a_supported_and_routable_locale(): void
    {
        $this->assertContains('ar', SetLocale::SUPPORTED);

        $this->get('/ar')->assertOk();
        $this->get('/ar/packages')->assertOk();
        $this->get('/ar/guide')->assertOk();
        $this->get('/ar/contact')->assertOk();
    }

    public function test_arabic_pages_render_right_to_left(): void
    {
        $html = $this->get('/ar')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*dir="rtl"/', $html);
        $this->assertMatchesRegularExpression('/<html[^>]*lang="ar"/', $html);
    }

    /** Dhivehi is unaffected: it does not become "every non-English locale". */
    public function test_dhivehi_still_renders_right_to_left_and_english_left_to_right(): void
    {
        $this->assertTrue(SetLocale::isRtl('dv'));
        $this->assertTrue(SetLocale::isRtl('ar'));
        $this->assertFalse(SetLocale::isRtl('en'));

        $dv = $this->get('/dv')->assertOk()->getContent();
        $en = $this->get('/en')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*dir="rtl"/', $dv);
        $this->assertMatchesRegularExpression('/<html[^>]*dir="ltr"/', $en);
    }

    public function test_arabic_gains_no_content_of_its_own_yet(): void
    {
        // With no resources/lang/ar, every group key falls back to English —
        // the same gap Dhivehi has for its own untranslated strings, not a
        // fabricated Arabic string standing in for a real one.
        $this->assertDirectoryDoesNotExist(lang_path('ar'));

        $this->get('/ar')
            ->assertOk()
            ->assertSee('Packages')
            ->assertSee('Umrah Guide');
    }

    public function test_hreflang_offers_an_arabic_alternate(): void
    {
        $request = Request::create('/en/packages');

        $this->assertArrayHasKey('ar', Seo::alternates($request));
        $this->assertStringEndsWith('/ar/packages', Seo::alternates($request)['ar']);
    }

    /** Switching language from an Arabic page strips the right prefix. */
    public function test_switching_locale_away_from_arabic_strips_its_prefix(): void
    {
        $this->withSession(['app_locale' => 'ar'])
            ->from('/ar/packages')
            ->get('/lang/en')
            ->assertRedirect('/en/packages');
    }
}
