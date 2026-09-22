<?php

namespace Tests\Feature;

use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PWA was wired up but never connected.
 *
 * No page linked the manifest, so the site could not be installed at all. The
 * manifest declared scope and start_url of /guide — one page — and pointed at
 * two icon files that did not exist. The service worker registration sat in a
 *
 * @push('scripts') block on the guide page, and no layout rendered a 'scripts'
 * stack, so it never ran. And the worker itself could not install: addAll()
 * rejects the whole batch on a single 404, and three of its five entries were
 * missing.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);

        $this->assertIsArray($manifest, 'manifest.json is not valid JSON.');

        return $manifest;
    }

    public function test_the_manifest_is_linked_from_public_pages(): void
    {
        $this->get('/en')
            ->assertOk()
            ->assertSee('rel="manifest"', false);

        $this->get('/en/guide')
            ->assertOk()
            ->assertSee('rel="manifest"', false);
    }

    /**
     * scope and start_url were both /guide, which after the move to
     * locale-prefixed URLs does not even resolve — /guide is now a 301.
     */
    public function test_the_manifest_covers_the_whole_site(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('/', $manifest['start_url']);
    }

    public function test_the_start_url_resolves(): void
    {
        $this->get($this->manifest()['start_url'])->assertRedirect('/en');
    }

    /**
     * Chrome refuses to install a manifest whose icons 404, and it needs at
     * least one of 192px or larger plus a maskable one to avoid a letterboxed
     * icon on Android.
     */
    public function test_every_declared_icon_exists(): void
    {
        $manifest = $this->manifest();

        $this->assertNotEmpty($manifest['icons']);

        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));

            $this->assertFileExists($path, "Manifest icon {$icon['src']} does not exist.");

            [$width, $height] = getimagesize($path);

            $this->assertSame(
                $icon['sizes'],
                "{$width}x{$height}",
                "Manifest icon {$icon['src']} is {$width}x{$height}, declared {$icon['sizes']}.",
            );
        }

        $purposes = array_column($manifest['icons'], 'purpose');

        $this->assertContains('any', $purposes);
        $this->assertContains('maskable', $purposes);
    }

    /**
     * The layout has referenced these since before this change and none of
     * them existed, so every page requested four 404s.
     */
    public function test_the_icons_the_layout_references_exist(): void
    {
        foreach ([
            'favicon.ico',
            'apple-touch-icon.png',
            'favicon-32x32.png',
            'favicon-16x16.png',
        ] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }

    /**
     * A single missing entry used to reject the whole install and leave the
     * cache empty, so every precached URL must actually be there.
     */
    public function test_every_precached_url_exists(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        preg_match('/const SHELL = \[(.*?)\];/s', $worker, $matches);

        $this->assertNotEmpty($matches, 'Could not find the precache list in sw.js.');

        preg_match_all("/'([^']+)'/", $matches[1], $urls);

        $this->assertNotEmpty($urls[1]);

        foreach ($urls[1] as $url) {
            $this->assertFileExists(
                public_path(ltrim($url, '/')),
                "sw.js precaches {$url}, which does not exist.",
            );
        }
    }

    /**
     * A cached asset whose URL outlives its contents is a stale asset forever.
     *
     * This is the bug behind "in mobile footer i see old footer". The worker
     * served everything under `/images/` cache-first with no revalidation, so
     * `/images/rihla-mark-inverse.svg` — the same path before and after the
     * rebrand — stayed at whatever bytes a phone happened to see first. The
     * stylesheet beside it updated normally, because Vite renames a build
     * asset whenever its contents change and a new name is a new cache entry.
     * The result was the new palette wrapped around the previous logo, on
     * every returning phone, invisible to anyone testing in a fresh browser.
     *
     * Only the hashed build output may be served cache-first. Everything else
     * has to go out to the network on every request, even when it answers from
     * cache first.
     *
     * Proved in a real browser rather than here: load the page, let the worker
     * install, change a file under `public/images/`, reload, and check what
     * the page receives. Before the fix it was the old bytes on every reload;
     * after it, the new ones. What this test can see is only the routing.
     */
    public function test_only_hashed_build_assets_are_served_cache_first(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        preg_match('/function isImmutableAsset\(url\) \{(.*?)\n\}/s', $worker, $immutable);

        $this->assertNotEmpty($immutable, 'sw.js has no isImmutableAsset predicate.');

        foreach (['/images/', '/fonts/', '/js/'] as $path) {
            $this->assertStringNotContainsString($path, $immutable[1],
                "sw.js treats {$path} as immutable, so a file that changes without changing "
                .'its name will be served from cache until VERSION is bumped by hand.');
        }

        $this->assertStringContainsString('/build/assets/', $immutable[1],
            'Nothing is served cache-first, so the hashed build output is refetched every time.');
    }

    /**
     * The revalidation has to happen whether or not the cache hit.
     *
     * Serving the cached copy is what keeps the site fast and usable with no
     * signal; going to the network anyway is what stops that copy being the
     * last word. `event.waitUntil` is load-bearing — returning the cached
     * response settles the fetch event, and the browser may kill the worker
     * before the write finishes without it.
     */
    public function test_mutable_assets_are_revalidated_on_every_request(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        preg_match('/async function handleMutableAsset\(event\) \{(.*?)\n\}/s', $worker, $handler);

        $this->assertNotEmpty($handler, 'sw.js has no handler for assets that can change in place.');

        $body = $handler[1];

        $this->assertStringContainsString('fetch(request)', $body,
            'The mutable-asset handler never goes to the network.');

        $this->assertStringContainsString('event.waitUntil', $body,
            'The revalidation is not kept alive past the response, so the worker may be '
            .'killed before it writes the fresh copy.');

        $this->assertMatchesRegularExpression('/cache\.put\(request, response\.clone\(\)\)/', $body,
            'The mutable-asset handler fetches but never replaces what it had cached.');

        // The routing has to actually use it.
        $this->assertStringContainsString('handleMutableAsset(event)', $worker,
            'handleMutableAsset is defined but nothing routes to it.');

        foreach (['/images/', '/fonts/', '/js/'] as $path) {
            $this->assertStringContainsString($path, $worker,
                "sw.js no longer mentions {$path} at all, so it is not being cached by any path.");
        }
    }

    public function test_the_service_worker_is_registered_site_wide(): void
    {
        $this->get('/en')
            ->assertOk()
            ->assertSee('serviceWorker', false);

        $this->get('/en/trips')
            ->assertOk()
            ->assertSee('serviceWorker', false);
    }

    /**
     * The guide pushes a print stylesheet onto a 'styles' stack and the layout
     * rendered no such stack, so the stylesheet never reached the page.
     */
    public function test_the_layout_renders_the_stacks_views_push_to(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        foreach (['styles', 'scripts', 'schema'] as $stack) {
            $this->assertStringContainsString("@stack('{$stack}')", $layout);
        }

        $this->get('/en/guide')
            ->assertOk()
            // Pushed by the guide's @push('styles') block.
            ->assertSee('@media print', false);
    }

    /**
     * The offline page paints itself, so it goes stale on its own.
     *
     * It is a standalone document with an inline stylesheet — no Tailwind, no
     * `Brand::` — and the service worker precaches it, so it is genuinely
     * served. This assertion named one retired blue, which meant it kept
     * passing through the whole wine-to-violet change while the page sat at a
     * wine-to-ink gradient with gold ticks. Naming one dead colour only ever
     * catches that colour.
     *
     * `BrandColourTest` now scans this file against the full retired list.
     * What is left here is the other half: the page must actually carry the
     * brand, not merely avoid one old hex.
     */
    public function test_the_offline_page_is_served_and_branded(): void
    {
        $offline = file_get_contents(public_path('offline.html'));

        $this->assertStringContainsString(Brand::WINE, $offline,
            'The offline page does not carry the brand primary.');

        $this->assertStringContainsString(Brand::INK, $offline,
            'The offline page does not carry the brand ink.');
    }
}
