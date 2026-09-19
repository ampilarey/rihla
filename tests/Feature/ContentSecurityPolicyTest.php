<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The policy, and the two concessions it makes.
 *
 * What it buys: a browser will run the scripts this application marked with
 * the request's nonce, and refuse every other one — including any that arrives
 * inside a trip title, a guide step or a media caption. That is how cross-site
 * scripting nearly always gets in, and it is now blocked at the browser rather
 * than relying on every future output being escaped correctly.
 *
 * It could not be written until the 36 inline event handlers were gone: a
 * nonce marks a <script> block, and can never vouch for code in an attribute.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(string $uri = '/en'): string
    {
        return (string) $this->get($uri)->assertOk()->headers->get('Content-Security-Policy');
    }

    public function test_every_page_carries_an_enforcing_policy(): void
    {
        foreach (['/en', '/dv', '/en/trips', '/en/guide', '/login'] as $uri) {
            $response = $this->get($uri);

            $this->assertNotNull($response->headers->get('Content-Security-Policy'),
                "{$uri} is served without a policy.");
            $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'),
                'Report-only reports and blocks nothing; it must not be the default.');
        }
    }

    /**
     * The point of the whole exercise. A policy carrying 'unsafe-inline' in
     * script-src runs injected script tags exactly as before, while looking
     * like protection on a checklist.
     */
    public function test_script_src_does_not_allow_inline_script(): void
    {
        preg_match('/script-src ([^;]*)/', $this->policy(), $m);

        $this->assertNotEmpty($m, 'No script-src at all.');
        $this->assertStringNotContainsString("'unsafe-inline'", $m[1],
            "'unsafe-inline' in script-src would undo the entire policy.");
        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\/=]{16,}'/", $m[1]);
    }

    /** A page whose nonce does not match its header runs none of its own scripts. */
    public function test_the_nonce_in_the_header_is_the_nonce_in_the_page(): void
    {
        $response = $this->get('/en')->assertOk();

        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $header);
        preg_match_all('/nonce="([^"]+)"/', $response->getContent(), $body);

        $this->assertNotEmpty($header);
        $this->assertNotEmpty($body[1], 'No inline script carried a nonce.');
        $this->assertSame([$header[1]], array_values(array_unique($body[1])));
    }

    /**
     * A nonce reused between responses is one an attacker can read from a
     * cached page and then put on their own injected tag.
     */
    public function test_the_nonce_is_new_on_every_response(): void
    {
        $first = $this->get('/en')->headers->get('Content-Security-Policy');
        $second = $this->get('/en')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    /** Each of these closes a way around script-src. */
    public function test_the_policy_closes_the_usual_side_doors(): void
    {
        $policy = $this->policy();

        foreach ([
            "object-src 'none'" => '<object> runs plugins the other directives never see',
            "base-uri 'self'" => 'an injected <base> silently repoints every relative script URL',
            "form-action 'self'" => 'an injected login box would post the password elsewhere',
            "frame-ancestors 'self'" => 'clickjacking the admin panel',
            "default-src 'self'" => 'anything not named above',
        ] as $directive => $why) {
            $this->assertStringContainsString($directive, $policy, "Missing {$directive}: {$why}.");
        }
    }

    /** Both switches exist for an emergency, and neither is the default. */
    public function test_the_policy_can_be_made_report_only(): void
    {
        config(['security.csp_report_only' => true]);

        $response = $this->get('/en')->assertOk();

        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_the_policy_can_be_switched_off(): void
    {
        config(['security.csp_enabled' => false]);

        $this->assertNull($this->get('/en')->headers->get('Content-Security-Policy'));
    }

    /**
     * An inline block without a nonce does not warn — it silently does not
     * run, and whatever it powered stops working.
     */
    public function test_every_inline_script_block_carries_a_nonce(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $contents = (string) File::get($file->getPathname());

            // A <script> carrying no nonce. Two kinds legitimately need none:
            // src'd scripts, which 'self' covers, and application/ld+json,
            // which a browser never executes — so script-src is never checked
            // against it. Verified in Chrome: the structured data blocks raise
            // no violation without a nonce, and nonceing them would only risk
            // confusing the crawlers that read them.
            $pattern = '/<script(?![^>]*\bnonce=)(?![^>]*\bsrc=)(?![^>]*application\/ld\+json)[^>]*>/';

            if (preg_match_all($pattern, $contents, $m)) {
                $offenders[] = $file->getFilename().': '.implode(', ', $m[0]);
            }
        }

        $this->assertSame([], $offenders, 'These blocks will silently stop running under the policy.');
    }

    /**
     * SortableJS came from jsDelivr with no integrity attribute, on the admin
     * page that reorders hero banners. A compromised CDN would have had code
     * execution in a signed-in administrator's browser, and an unreachable one
     * simply broke the page. It ships with the application now, so the policy
     * needs no third-party script origin at all.
     */
    public function test_no_view_loads_a_script_from_another_origin(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (preg_match_all('/<script[^>]*\bsrc="(https?:)?\/\/[^"]+"/', (string) File::get($file->getPathname()), $m)) {
                $offenders[] = $file->getFilename().': '.implode(', ', $m[0]);
            }
        }

        $this->assertSame([], $offenders);
        $this->assertFileExists(public_path('vendor/sortablejs/Sortable.min.js'));

        $this->assertStringNotContainsString('cdn.jsdelivr.net', $this->policy('/en'),
            'No third-party script origin should be needed.');
    }

    /**
     * Recorded rather than hidden. style-src keeps 'unsafe-inline' because
     * colours chosen in the admin panel are written into inline style
     * attributes, which a nonce cannot cover — only style-src-attr can, and it
     * is not supported widely enough to rely on. Style injection is a real but
     * much smaller problem than script injection. If the hero-banner colours
     * ever stop being inline, this test is the reminder to tighten it.
     */
    public function test_the_style_concession_is_the_only_one_of_its_kind(): void
    {
        $policy = $this->policy();

        preg_match('/style-src ([^;]*)/', $policy, $style);
        $this->assertStringContainsString("'unsafe-inline'", $style[1]);

        // And nowhere else.
        $this->assertSame(1, substr_count($policy, "'unsafe-inline'"),
            "'unsafe-inline' belongs in style-src and nowhere else.");
    }
}
