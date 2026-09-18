<?php

namespace Tests\Feature;

use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public URLs carry their locale as the first path segment.
 *
 * Before this, both languages lived at the same path and the choice was held
 * in the session. Every page therefore had exactly one indexable URL — search
 * engines could only ever see English — and a link shared with someone showed
 * them whichever language they had last picked rather than the one the sender
 * was looking at.
 */
class LocaleRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_locale_has_its_own_url(): void
    {
        $this->get('/en/guide')->assertOk();
        $this->get('/dv/guide')->assertOk();
    }

    public function test_an_unknown_locale_is_not_a_page(): void
    {
        $this->get('/fr/guide')->assertNotFound();
        $this->get('/fr')->assertNotFound();
    }

    /**
     * The whole design rests on this: route() picks the locale up from
     * URL::defaults(), so not a single view had to be changed.
     */
    public function test_route_generation_carries_the_current_locale(): void
    {
        $this->get('/dv/guide');

        $this->assertSame('/dv/trips', parse_url(route('trips.index'), PHP_URL_PATH));

        $this->get('/en/guide');

        $this->assertSame('/en/trips', parse_url(route('trips.index'), PHP_URL_PATH));
    }

    /**
     * The switcher route's parameter is named `code`, not `locale`, precisely
     * so URL::defaults() cannot substitute itself into it. If it could, both
     * links would point at the language already being read and the site would
     * have no way to change language at all.
     */
    public function test_the_language_switcher_links_render_both_languages(): void
    {
        $this->get('/en/guide')
            ->assertSee('/lang/en', false)
            ->assertSee('/lang/dv', false);
    }

    public function test_the_bare_domain_forwards_to_a_locale(): void
    {
        $this->get('/')->assertRedirect('/en');

        // A visitor who has chosen Dhivehi keeps it.
        $this->withSession(['app_locale' => 'dv'])->get('/')->assertRedirect('/dv');
    }

    public function test_the_bare_domain_honours_accept_language(): void
    {
        $this->get('/', ['Accept-Language' => 'dv,en;q=0.5'])->assertRedirect('/dv');
    }

    /**
     * Anything already linked or indexed under the old unprefixed paths has to
     * keep working, and land on the canonical URL rather than a soft 404.
     */
    public function test_the_pre_prefix_urls_redirect_permanently(): void
    {
        foreach (['/trips', '/gallery', '/social', '/contact', '/guide', '/guide/pdf'] as $legacy) {
            $this->get($legacy)
                ->assertStatus(301)
                ->assertRedirect('/en'.$legacy);
        }
    }

    public function test_a_legacy_trip_url_keeps_its_slug(): void
    {
        $this->get('/trips/seven-nights-madinah')
            ->assertStatus(301)
            ->assertRedirect('/en/trips/seven-nights-madinah');
    }

    /**
     * Switching language has to move the visitor to the same page in the other
     * language. Updating the session alone would leave them on /en/guide
     * reading Dhivehi, and a refresh would flip the page back to English.
     */
    public function test_switching_language_rewrites_the_current_path(): void
    {
        $this->get('/lang/dv', ['referer' => 'http://localhost/en/trips'])
            ->assertRedirect('/dv/trips');

        $this->get('/lang/en', ['referer' => 'http://localhost/dv/guide'])
            ->assertRedirect('/en/guide');
    }

    public function test_switching_language_from_the_homepage(): void
    {
        $this->get('/lang/dv', ['referer' => 'http://localhost/en'])
            ->assertRedirect('/dv');
    }

    public function test_switching_language_preserves_the_query_string(): void
    {
        $this->get('/lang/dv', ['referer' => 'http://localhost/en/trips?page=2'])
            ->assertRedirect('/dv/trips?page=2');
    }

    /**
     * Admin and auth pages are not localised in the path, so there is no
     * prefix to rewrite — the language is recorded and the visitor stays put.
     */
    public function test_switching_language_on_an_unprefixed_page_stays_put(): void
    {
        $this->get('/lang/dv', ['referer' => 'http://localhost/login'])
            ->assertRedirect('http://localhost/login');

        $this->assertSame('dv', session('app_locale'));
    }

    public function test_an_unknown_language_code_changes_nothing(): void
    {
        $this->withSession(['app_locale' => 'en'])
            ->get('/lang/fr', ['referer' => 'http://localhost/en/guide'])
            ->assertRedirect('http://localhost/en/guide');

        $this->assertSame('en', session('app_locale'));
    }

    /**
     * The prefix is consumed by the middleware. If it were not, it would be
     * passed to the controller as its first argument and TripController::show()
     * would receive 'en' where it expects a slug.
     */
    public function test_the_prefix_is_not_passed_to_the_controller(): void
    {
        Trip::create([
            'title' => 'Seven Nights in Madinah',
            'slug' => 'seven-nights-madinah',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-08',
            'status' => 'upcoming',
            'is_published' => true,
        ]);

        $this->get('/en/trips/seven-nights-madinah')->assertOk();
    }
}
