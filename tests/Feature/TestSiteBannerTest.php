<?php

namespace Tests\Feature;

use App\Support\TestSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The red bar that shows a merge has reached test.rihla.mv. It rides `main`
 * to production with everything else, so what matters most here is the
 * negative: rihla.mv, and every host that is not the test site, shows
 * nothing.
 */
class TestSiteBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_test_site_wears_a_red_bar_naming_its_build(): void
    {
        $page = $this->get('http://test.rihla.mv/en')
            ->assertOk()
            ->assertSee('data-test-site-banner', false)
            ->assertSee('TEST SITE · updated automatically from main');

        $build = TestSite::build();

        if ($build !== null) {
            $page->assertSee('build '.$build);
        }
    }

    public function test_production_and_every_other_host_show_nothing(): void
    {
        $this->get('http://rihla.mv/en')->assertOk()->assertDontSee('data-test-site-banner', false);
        $this->get('http://www.rihla.mv/en')->assertOk()->assertDontSee('data-test-site-banner', false);
        $this->get('/en')->assertOk()->assertDontSee('data-test-site-banner', false);
    }

    /** Behind a proxy that rewrites the host, APP_URL still says which site this is. */
    public function test_an_app_url_on_the_test_host_is_enough(): void
    {
        config(['app.url' => 'https://test.rihla.mv']);

        $this->get('/en')->assertOk()->assertSee('data-test-site-banner', false);
    }
}
