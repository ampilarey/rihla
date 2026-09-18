<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The live 404 was Laravel's untouched default.
 *
 * `<title>Not Found</title>`, a grey box, no logo, no colour, no navigation,
 * no way back to the site, and English regardless of the locale in the URL.
 * Grepping the response for "Rihla", "rihla-logo" and "WhatsApp" returned
 * zero of each. With the legacy 301s and the sitemap now live, a visitor
 * following an old link is exactly the person most likely to land there.
 *
 * These pages are deliberately self-contained — no @extends, no @vite, no
 * database — because layouts.app now emits the Organization JSON-LD, which
 * reads the settings table. An error page built on it would query the
 * database to explain that the database is down, and throw a second time
 * inside the handler for the first throw. The tests below hold that line.
 */
class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    /** The statuses a visitor can actually be shown. */
    private const CODES = [403, 404, 419, 500, 503];

    public function test_every_handled_status_has_a_view(): void
    {
        foreach (self::CODES as $code) {
            $this->assertTrue(
                view()->exists("errors.{$code}"),
                "errors/{$code}.blade.php is missing, so status {$code} falls back to Laravel's default page.",
            );
        }
    }

    public function test_an_unknown_url_returns_a_branded_404(): void
    {
        $response = $this->get('/en/no-such-page-exists');

        $response->assertNotFound();

        // The exact strings whose absence made the old page unrecognisable.
        $response->assertSee(config('app.name'), false);
        $response->assertSee('#8E2653', false);     // wine
        $response->assertSee('#D2A03C', false);     // gold
        $response->assertSee(__('messages.error_404_title'), false);
    }

    /**
     * The old page was a dead end: nothing on it linked anywhere. Someone
     * arriving from a stale link had to retype the domain by hand.
     */
    public function test_the_404_offers_a_way_back_into_the_site(): void
    {
        $this->get('/en/no-such-page-exists')
            ->assertSee('href="/en"', false)
            ->assertSee('href="/en/trips"', false);

        $this->get('/dv/no-such-page-exists')
            ->assertSee('href="/dv"', false)
            ->assertSee('href="/dv/trips"', false);
    }

    /**
     * A 404 under /dv must come back as a Dhivehi document, right-to-left,
     * with the Thaana face available. The bundle is not loaded here, so the
     *
     * @font-face has to be inline or Thaana falls back to whatever the
     * browser happens to have — the bug that was just fixed everywhere else.
     */
    public function test_the_dhivehi_404_is_right_to_left_with_a_thaana_face(): void
    {
        $this->get('/dv/no-such-page-exists')
            ->assertSee('lang="dv"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('A_faruma.woff2', false);
    }

    public function test_error_pages_are_not_indexed(): void
    {
        $this->get('/en/no-such-page-exists')
            ->assertSee('name="robots" content="noindex, nofollow"', false);
    }

    /**
     * The point of the whole exercise. If rendering an error page issues a
     * query, then the 500 page cannot survive the outage that caused the 500.
     */
    public function test_rendering_an_error_page_issues_no_queries(): void
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        foreach (self::CODES as $code) {
            view("errors.{$code}", ['exception' => new HttpException($code)])->render();
        }

        $this->assertSame([], $queries, implode("\n", array_merge(
            ['Error pages ran database queries while rendering:'],
            $queries,
        )));
    }

    /**
     * Structural backstop for the test above: the query count can only stay
     * at zero for as long as nobody reaches for the site layout or the Vite
     * bundle, both of which pull in far more than they look like they do.
     */
    public function test_error_views_stay_self_contained(): void
    {
        $offenders = [];

        foreach (array_merge(self::CODES, ['layout']) as $view) {
            $source = File::get(resource_path("views/errors/{$view}.blade.php"));

            foreach (['@vite', "@extends('layouts.", '@include', '@livewire'] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $offenders[] = "errors/{$view}.blade.php uses {$forbidden}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Error views must not depend on the application shell:'],
            $offenders,
        )));
    }

    /**
     * route() reads URL::defaults(), which SetLocale populates. A failure
     * early in the request can reach the error handler before that has run,
     * and a route() call there would throw inside the handler. The links are
     * literal paths for that reason; this stops them drifting back.
     */
    public function test_error_views_do_not_generate_routes(): void
    {
        $source = File::get(resource_path('views/errors/layout.blade.php'));

        $this->assertStringNotContainsString('route(', $source,
            'errors/layout.blade.php calls route(), which depends on URL::defaults() '.
            'and can throw inside the error handler. Use a literal path.');
    }
}
