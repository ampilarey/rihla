<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The live site sent no security headers at all, and advertised its exact PHP
 * version in every response.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_carry_the_headers(): void
    {
        $response = $this->get('/en')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * Applied to every response, not only the web group: the JSON API and the
     * deploy webhook should not advertise the PHP version either.
     */
    public function test_the_json_api_carries_them_too(): void
    {
        $this->get('/api/guide-steps?locale=en')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_php_version_is_not_advertised(): void
    {
        $this->get('/en')->assertOk()->assertHeaderMissing('X-Powered-By');
    }

    /**
     * The gallery embeds YouTube with allowfullscreen. Naming fullscreen in
     * the policy — even permissively — is the kind of change that silently
     * stops a video expanding, so the header must list only features the site
     * does not use.
     */
    public function test_the_permissions_policy_does_not_restrict_video(): void
    {
        $policy = $this->get('/en')->assertOk()->headers->get('Permissions-Policy');

        $this->assertNotNull($policy);

        foreach (['geolocation=()', 'camera=()', 'microphone=()', 'payment=()'] as $denied) {
            $this->assertStringContainsString($denied, $policy);
        }

        foreach (['fullscreen', 'autoplay', 'encrypted-media', 'picture-in-picture'] as $needed) {
            $this->assertStringNotContainsString(
                $needed,
                $policy,
                "Permissions-Policy names {$needed}, which the YouTube embed needs.",
            );
        }
    }

    /**
     * Over plain HTTP the header is meaningless, and in local development it
     * would teach the browser to refuse the site it is being served.
     */
    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('http://localhost/en')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/en')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    /**
     * Both extend the promise to hosts this application does not control, and
     * preload is effectively permanent.
     */
    public function test_hsts_claims_nothing_about_subdomains(): void
    {
        $header = $this->get('https://localhost/en')->headers->get('Strict-Transport-Security');

        $this->assertStringNotContainsString('includeSubDomains', (string) $header);
        $this->assertStringNotContainsString('preload', (string) $header);
    }

    public function test_hsts_can_be_turned_off_without_a_deploy(): void
    {
        config(['security.hsts_max_age' => 0]);

        $this->get('https://localhost/en')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }
}
