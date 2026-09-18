<?php

namespace Tests\Feature;

use Database\Seeders\HeroBannerSeeder;
use Database\Seeders\MediaSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TripSeeder;
use Database\Seeders\UmrahGuideSeeder;
use Database\Seeders\WhySectionSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The logo was the heaviest thing on the site by a wide margin.
 *
 * public/images/rihla-logo.png is 6250 × 2976 and weighs 1.44 MB. It was
 * served untouched in the header, again in the footer, and again as the
 * og:image every scraper fetches — to be drawn 80 pixels tall. One file was
 * seventeen times the weight of the entire CSS and JavaScript bundle
 * combined, and the first thing a visitor on a Maldivian mobile connection
 * had to download before seeing anything.
 *
 * The artwork is unchanged. The files these tests guard are resampled from
 * that exact PNG, which is still in the repository untouched; only the
 * resolution handed to a browser is different.
 */
class ImageWeightTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/en', '/en/trips', '/en/gallery', '/en/contact', '/en/guide', '/dv'];

    /** No image a page loads on sight should approach this. */
    private const BUDGET_BYTES = 120 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            SettingsSeeder::class,
            TripSeeder::class,
            MediaSeeder::class,
            HeroBannerSeeder::class,
            WhySectionSeeder::class,
            UmrahGuideSeeder::class,
        ]);
    }

    private function xpath(string $path): DOMXPath
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$this->get($path)->getContent());
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /** The original is the brand asset. It stays exactly as it is. */
    public function test_the_original_logo_artwork_is_still_shipped(): void
    {
        $original = public_path('images/rihla-logo.png');

        $this->assertFileExists($original);

        [$width, $height] = getimagesize($original);

        $this->assertSame(6250, $width, 'The original logo has been resized. It should not be.');
        $this->assertSame(2976, $height, 'The original logo has been resized. It should not be.');
    }

    /** Every derivative must carry the same proportions as the original. */
    public function test_the_served_logos_match_the_original_proportions(): void
    {
        [$sourceWidth, $sourceHeight] = getimagesize(public_path('images/rihla-logo.png'));
        $ratio = $sourceWidth / $sourceHeight;

        foreach ([200, 400, 600] as $width) {
            foreach (['png', 'webp'] as $format) {
                $path = public_path("images/rihla-logo-{$width}.{$format}");

                $this->assertFileExists($path, "Missing {$width}px {$format} logo.");

                [$w, $h] = getimagesize($path);

                $this->assertSame($width, $w);
                $this->assertEqualsWithDelta($ratio, $w / $h, 0.01,
                    "rihla-logo-{$width}.{$format} has been stretched.");
                $this->assertLessThan(60 * 1024, filesize($path),
                    "rihla-logo-{$width}.{$format} is heavier than it should be.");
            }
        }
    }

    /** No page may pull in a heavyweight image just to draw a small one. */
    public function test_no_page_loads_an_oversized_image(): void
    {
        $oversized = [];

        foreach (self::PAGES as $page) {
            foreach ($this->xpath($page)->query('//img | //source') as $el) {
                $candidates = array_filter(array_map(
                    fn ($part) => trim(explode(' ', trim($part))[0]),
                    explode(',', $el->getAttribute('srcset').','.$el->getAttribute('src')),
                ));

                foreach ($candidates as $url) {
                    $path = public_path(parse_url($url, PHP_URL_PATH) ?? '');

                    if (! File::exists($path) || File::isDirectory($path)) {
                        continue;   // Storage-backed uploads are not in the repo.
                    }

                    if (filesize($path) > self::BUDGET_BYTES) {
                        $oversized[] = sprintf('%s: %s is %s KB', $page, basename($path),
                            number_format(filesize($path) / 1024));
                    }
                }
            }
        }

        $this->assertSame([], array_unique($oversized), implode("\n", array_merge(
            ['Pages reference images above the '.(self::BUDGET_BYTES / 1024).' KB budget:'],
            array_unique($oversized),
        )));
    }

    /**
     * width and height let the browser reserve the right space before the
     * image arrives. Without them the page reflows as each one lands.
     */
    public function test_the_logo_declares_its_intrinsic_size(): void
    {
        foreach (self::PAGES as $page) {
            foreach ($this->xpath($page)->query('//img[contains(@src, "rihla-logo")]') as $img) {
                $this->assertSame('6250', $img->getAttribute('width'), "{$page}: logo has no intrinsic width.");
                $this->assertSame('2976', $img->getAttribute('height'), "{$page}: logo has no intrinsic height.");
            }
        }
    }

    /**
     * Tailwind's spacing scale has no 50, so the header's `w-50` compiled to
     * nothing and never did anything. A class that emits no CSS is worse than
     * no class: it reads like a constraint that is being honoured, and the
     * logo was quietly falling back to its intrinsic width instead.
     *
     * Only bare, unprefixed utilities are checked. `min-w-0` contains "w-0"
     * without being it, and a variant like `md:w-7` compiles to `.md\:w-7`
     * inside a media query — both are fine, and an earlier version of this
     * test reported all three as broken.
     */
    public function test_no_view_uses_a_width_class_tailwind_does_not_emit(): void
    {
        $css = File::get(File::glob(public_path('build/assets/*.css'))[0]);

        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', File::get($file));

            preg_match_all('/class="([^"]*)"/', (string) $source, $attributes);

            foreach ($attributes[1] as $attribute) {
                foreach (preg_split('/\s+/', $attribute) as $class) {
                    if (preg_match('/^w-\d+$/', $class) && ! str_contains($css, '.'.$class.'{')) {
                        $offenders[] = basename($file->getPathname()).": {$class}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), implode("\n", array_merge(
            ['Views use width classes that the compiled stylesheet does not define:'],
            array_unique($offenders),
        )));
    }

    /** Below-the-fold images should not compete with the page for bandwidth. */
    public function test_images_declare_how_they_should_be_loaded(): void
    {
        $bare = [];

        foreach (self::PAGES as $page) {
            foreach ($this->xpath($page)->query('//img') as $img) {
                if ($img->getAttribute('loading') === '' || $img->getAttribute('decoding') === '') {
                    $bare[] = $page.': '.basename($img->getAttribute('src'));
                }
            }
        }

        $this->assertSame([], array_values(array_unique($bare)), implode("\n", array_merge(
            ['Images with no loading or decoding hint:'],
            array_unique($bare),
        )));
    }

    /** One definition, so the header and footer cannot drift apart. */
    public function test_the_logo_is_rendered_through_one_component(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_contains($path, 'brand-logo.blade.php')) {
                continue;
            }

            if (preg_match('/<img[^>]{0,200}rihla-logo/s', File::get($file))) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These views build a logo <img> by hand instead of using <x-brand-logo>:'],
            $offenders,
        )));
    }
}
