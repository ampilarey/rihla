<?php

namespace Tests\Feature;

use App\Models\HostPage;
use App\Models\Partner;
use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `{slug}.rihla.mv` — §16 Phase 16.
 *
 * The routes exist only when a domain is configured, and routes are read
 * when the application boots, so the domain is set in the environment
 * before it does.
 */
class HostSubdomainTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'rihla.test';

    protected function setUp(): void
    {
        putenv('HOST_SUBDOMAIN_DOMAIN='.self::DOMAIN);
        $_ENV['HOST_SUBDOMAIN_DOMAIN'] = $_SERVER['HOST_SUBDOMAIN_DOMAIN'] = self::DOMAIN;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('HOST_SUBDOMAIN_DOMAIN');
        unset($_ENV['HOST_SUBDOMAIN_DOMAIN'], $_SERVER['HOST_SUBDOMAIN_DOMAIN']);
    }

    private function host(string $slug, bool $published = true): Partner
    {
        $host = Partner::factory()->create(['slug' => $slug]);
        $factory = HostPage::factory();
        ($published ? $factory->published() : $factory)->create(['partner_id' => $host->id]);

        return $host;
    }

    public function test_a_hosts_subdomain_opens_their_page_in_the_readers_language(): void
    {
        $this->host('coral');

        $this->get('https://coral.'.self::DOMAIN.'/')
            ->assertStatus(302)
            ->assertRedirect('https://'.self::DOMAIN.'/en/stays/hosts/coral');

        $this->withHeader('Accept-Language', 'dv')
            ->get('https://coral.'.self::DOMAIN.'/anything/else')
            ->assertRedirect('https://'.self::DOMAIN.'/dv/stays/hosts/coral');
    }

    public function test_an_unknown_or_unpublished_host_is_not_found(): void
    {
        $this->host('draft-inn', published: false);
        $suspended = $this->host('closed-inn');
        $suspended->forceFill(['status' => Partner::STATUS_SUSPENDED])->save();

        $this->get('https://nobody.'.self::DOMAIN.'/')->assertNotFound();
        $this->get('https://draft-inn.'.self::DOMAIN.'/')->assertNotFound();
        $this->get('https://closed-inn.'.self::DOMAIN.'/')->assertNotFound();
    }

    /** The site's own names are never read as a host — not even one called that. */
    public function test_reserved_names_are_never_a_host(): void
    {
        $this->host('www');
        $this->host('test');

        // Served by the site as usual, not sent to a host's page.
        $this->get('https://www.'.self::DOMAIN.'/en')->assertOk()->assertSee('What we do');
        $this->assertStringNotContainsString('/stays/hosts/test', (string) $this->get('https://test.'.self::DOMAIN.'/')->headers->get('Location'));
    }

    public function test_the_main_domain_is_untouched(): void
    {
        Services::save(['stays_guesthouses' => Services::ON]);
        $this->host('coral');

        $this->get('https://'.self::DOMAIN.'/en')->assertOk()->assertSee('What we do');
        $this->get('https://'.self::DOMAIN.'/en/stays/hosts/coral')->assertOk();
    }
}
