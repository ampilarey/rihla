<?php

namespace Tests\Feature;

use App\Support\CanonicalUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Every absolute link comes from APP_URL, never from the request's Host
 * header — site audit. A vhost answering for a foreign name would
 * otherwise put that name into a password-reset mail.
 */
class CanonicalUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_come_from_the_configured_url_when_enforced(): void
    {
        config(['app.url' => 'https://rihla.mv', 'app.force_url' => true]);
        CanonicalUrl::enforce();

        $this->get('http://attacker.example/up')->assertOk();

        $this->assertSame('https://rihla.mv/reset-password/abc', URL::to('/reset-password/abc'));
        $this->assertSame('https://rihla.mv/en', route('home', ['locale' => 'en']));
    }

    public function test_it_is_always_on_in_production(): void
    {
        config(['app.url' => 'https://rihla.mv', 'app.force_url' => false]);
        $this->app['env'] = 'production';
        CanonicalUrl::enforce();

        $this->assertSame('https://rihla.mv/en', URL::to('/en'));
    }
}
