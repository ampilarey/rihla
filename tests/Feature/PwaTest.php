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
