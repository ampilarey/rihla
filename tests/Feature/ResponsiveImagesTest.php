<?php

namespace Tests\Feature;

use App\Models\HeroBanner;
use App\Models\Package;
use App\Support\ResponsiveImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Serving an image at the size the device asked for — §10.1.
 *
 * The variants were always there. Hero uploads have written `_768w`,
 * `_1280w` and `_1920w` WebP files since Phase 2 and
 * `HeroBanner::getResponsiveImageUrlsAttribute()` has returned their URLs —
 * and **no view ever called it**. Every visitor on every device got the
 * full-size file, and on the homepage that file is the Largest Contentful
 * Paint element, so a telephone downloaded a 1920-pixel photograph to show
 * it 390 pixels wide.
 *
 * Three properties, and the second is the one that would have made things
 * worse rather than better:
 *
 * 1. **A `srcset` appears when the variants exist.**
 * 2. **It does not when they do not.** That accessor builds URLs by string
 *    manipulation and never asks the disk. A srcset of three 404s is
 *    strictly worse than none: the browser picks one and shows nothing,
 *    where without it the original would have loaded.
 * 3. **The hero falls back to its gradient, not to the stand-in.** The
 *    hero's title is white and sits over the image; the cream stand-in
 *    leaves white text on near-white and the headline disappears.
 */
class ResponsiveImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** A real JPEG, because the generator decodes what it is given. */
    private function jpeg(string $path, int $width = 2400, int $height = 1200): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 142, 38, 83));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $bytes);
    }

    /**
     * Remove the variants the upload observer just made.
     *
     * Creating a record now generates them, which is the point — but it
     * means a test of `images:responsive` would pass without the command
     * doing anything at all. Clearing them first is what makes each of
     * those tests test the thing it names.
     */
    private function forgetVariants(string $path): void
    {
        foreach (ResponsiveImage::WIDTHS as $width) {
            Storage::disk('public')->delete(ResponsiveImage::variantPath($path, $width));
        }
    }

    // ── Naming ───────────────────────────────────────────────────────────

    public function test_a_variant_is_named_after_the_original_and_its_width(): void
    {
        $this->assertSame('hero/banner_768w.webp', ResponsiveImage::variantPath('hero/banner.jpg', 768));
        $this->assertSame('hero/banner_1920w.webp', ResponsiveImage::variantPath('hero/banner.webp', 1920));
    }

    /** Which is the convention the hero uploader has always written. */
    public function test_the_naming_matches_what_uploads_already_produce(): void
    {
        $this->assertStringContainsString(
            '_1920w.webp',
            ResponsiveImage::variantPath('hero/2026-01-01-abc.png', 1920),
        );
    }

    // ── Asking the disk ──────────────────────────────────────────────────

    /**
     * Property 2, and the reason this class exists rather than the
     * accessor that was already here.
     */
    public function test_a_variant_that_is_not_on_disk_is_not_offered(): void
    {
        $this->jpeg('hero/banner.jpg');
        Storage::disk('public')->put('hero/banner_768w.webp', 'pretend webp');

        $variants = ResponsiveImage::variants('hero/banner.jpg');

        $this->assertSame([768], array_keys($variants));
    }

    public function test_there_is_no_srcset_when_there_are_no_variants(): void
    {
        $this->jpeg('hero/banner.jpg');

        $this->assertNull(ResponsiveImage::srcset('hero/banner.jpg'));
    }

    public function test_the_srcset_names_each_width(): void
    {
        $this->jpeg('hero/banner.jpg');

        foreach (ResponsiveImage::WIDTHS as $width) {
            Storage::disk('public')->put(ResponsiveImage::variantPath('hero/banner.jpg', $width), 'x');
        }

        $srcset = (string) ResponsiveImage::srcset('hero/banner.jpg');

        foreach (ResponsiveImage::WIDTHS as $width) {
            $this->assertStringContainsString($width.'w', $srcset);
        }
    }

    // ── The component ────────────────────────────────────────────────────

    public function test_the_component_offers_a_srcset_and_sizes_when_it_can(): void
    {
        $this->jpeg('trips/cover.jpg');

        foreach (ResponsiveImage::WIDTHS as $width) {
            Storage::disk('public')->put(ResponsiveImage::variantPath('trips/cover.jpg', $width), 'x');
        }

        $html = (string) $this->blade(
            '<x-stored-image :path="$path" alt="A cover" sizes="50vw" />',
            ['path' => 'trips/cover.jpg'],
        );

        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('sizes="50vw"', $html);
        $this->assertStringContainsString('1280w', $html);
    }

    /** And falls back to one plain image rather than to three 404s. */
    public function test_the_component_offers_no_srcset_when_there_are_no_variants(): void
    {
        $this->jpeg('trips/cover.jpg');

        $html = (string) $this->blade(
            '<x-stored-image :path="$path" alt="A cover" sizes="50vw" />',
            ['path' => 'trips/cover.jpg'],
        );

        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringContainsString('trips/cover.jpg', $html);
    }

    /** The stand-in the component has always had is untouched. */
    public function test_a_missing_file_still_shows_the_branded_stand_in(): void
    {
        $html = (string) $this->blade(
            '<x-stored-image :path="$path" alt="A cover" />',
            ['path' => 'trips/gone.jpg'],
        );

        $this->assertStringContainsString('rihla-mark.svg', $html);
        $this->assertStringNotContainsString('<img src="'.Storage::disk('public')->url('trips/gone.jpg'), $html);
    }

    // ── The hero ─────────────────────────────────────────────────────────

    /**
     * Property 3. Found by looking at the page, not by a failing assertion.
     *
     * The hero's headline is white and sits over the image. With the
     * generic stand-in behind it — the dhoni mark on cream — the headline
     * was white on near-white and simply vanished. The gradient is the
     * fallback this component already had for a banner with no image at
     * all, and a banner whose file has gone is the same situation.
     */
    public function test_a_hero_whose_file_is_missing_falls_back_to_the_gradient(): void
    {
        HeroBanner::create([
            'title' => ['en' => 'A Banner'],
            'image_path' => 'hero/gone.jpg',
            'is_active' => true,
            'overlay_opacity' => 40,
        ]);

        $html = (string) $this->blade(
            '<x-hero-banner :banners="$banners" />',
            ['banners' => HeroBanner::query()->where('is_active', true)->get()],
        );

        $this->assertStringContainsString('hero-gradient-bg', $html);
        $this->assertStringNotContainsString('rihla-mark.svg', $html);
    }

    public function test_a_hero_whose_file_is_there_shows_it(): void
    {
        $this->jpeg('hero/banner.jpg');

        HeroBanner::create([
            'title' => ['en' => 'A Banner'],
            'image_path' => 'hero/banner.jpg',
            'is_active' => true,
            'overlay_opacity' => 40,
        ]);

        $html = (string) $this->blade(
            '<x-hero-banner :banners="$banners" />',
            ['banners' => HeroBanner::query()->where('is_active', true)->get()],
        );

        $this->assertStringContainsString('hero/banner.jpg', $html);
    }

    // ── At upload time ───────────────────────────────────────────────────

    /**
     * The half that makes the backfill stay true.
     *
     * Without this, every cover uploaded *after* `images:responsive` was
     * last run is full-size again until somebody remembers to run it —
     * which is exactly the state that command existed to leave.
     */
    public function test_saving_a_new_cover_generates_its_variants(): void
    {
        $this->jpeg('packages/cover.jpg', 2400, 1200);

        $package = Package::factory()->create(['cover_image' => 'packages/cover.jpg']);

        foreach (ResponsiveImage::WIDTHS as $width) {
            Storage::disk('public')->assertExists(ResponsiveImage::variantPath('packages/cover.jpg', $width));
        }

        $this->assertSame('packages/cover.jpg', $package->cover_image);
    }

    public function test_replacing_a_cover_generates_the_new_ones(): void
    {
        $this->jpeg('packages/first.jpg');
        $this->jpeg('packages/second.jpg');

        $package = Package::factory()->create(['cover_image' => 'packages/first.jpg']);

        $package->update(['cover_image' => 'packages/second.jpg']);

        Storage::disk('public')->assertExists(ResponsiveImage::variantPath('packages/second.jpg', 768));
    }

    /**
     * Editing a price should not re-encode three photographs.
     *
     * Only the save that changed the column does any work.
     */
    public function test_saving_an_unrelated_field_re_encodes_nothing(): void
    {
        $this->jpeg('packages/cover.jpg');

        $package = Package::factory()->create(['cover_image' => 'packages/cover.jpg']);

        $variant = ResponsiveImage::variantPath('packages/cover.jpg', 768);
        Storage::disk('public')->delete($variant);

        $package->touch();

        Storage::disk('public')->assertMissing($variant);
    }

    /**
     * A file GD cannot read must not cost an editor their work.
     *
     * The record saves and the original is served; only the size of the
     * download suffers, and failing the save would lose the whole edit
     * over that.
     */
    public function test_a_cover_that_will_not_encode_still_saves(): void
    {
        Storage::disk('public')->put('packages/not-really.jpg', 'this is not an image');

        $package = Package::factory()->create(['cover_image' => 'packages/not-really.jpg']);

        $this->assertSame('packages/not-really.jpg', $package->fresh()->cover_image);
        Storage::disk('public')->assertMissing(ResponsiveImage::variantPath('packages/not-really.jpg', 768));
    }

    // ── Generating them ──────────────────────────────────────────────────

    public function test_the_generator_makes_every_width_that_fits(): void
    {
        $this->jpeg('hero/banner.jpg', 2400, 1200);

        HeroBanner::create([
            'title' => ['en' => 'A Banner'],
            'image_path' => 'hero/banner.jpg',
            'is_active' => true,
            'overlay_opacity' => 40,
        ]);

        $this->forgetVariants('hero/banner.jpg');

        $this->artisan('images:responsive')->assertSuccessful();

        foreach (ResponsiveImage::WIDTHS as $width) {
            Storage::disk('public')->assertExists(ResponsiveImage::variantPath('hero/banner.jpg', $width));
        }
    }

    /**
     * A 900-pixel original gives a 768 and nothing else.
     *
     * Inventing a 1920-wide file from it makes a larger download that
     * looks worse, which is the opposite of the point.
     */
    public function test_the_generator_never_upscales(): void
    {
        $this->jpeg('hero/small.jpg', 900, 450);

        HeroBanner::create([
            'title' => ['en' => 'Small'],
            'image_path' => 'hero/small.jpg',
            'is_active' => true,
            'overlay_opacity' => 40,
        ]);

        $this->forgetVariants('hero/small.jpg');

        $this->artisan('images:responsive')->assertSuccessful();

        Storage::disk('public')->assertExists(ResponsiveImage::variantPath('hero/small.jpg', 768));
        Storage::disk('public')->assertMissing(ResponsiveImage::variantPath('hero/small.jpg', 1280));
        Storage::disk('public')->assertMissing(ResponsiveImage::variantPath('hero/small.jpg', 1920));
    }

    /** And what it makes is smaller than what it came from. */
    public function test_a_variant_weighs_less_than_the_original(): void
    {
        $this->jpeg('hero/banner.jpg', 2400, 1200);

        HeroBanner::create([
            'title' => ['en' => 'A Banner'],
            'image_path' => 'hero/banner.jpg',
            'is_active' => true,
            'overlay_opacity' => 40,
        ]);

        $this->forgetVariants('hero/banner.jpg');

        $this->artisan('images:responsive')->assertSuccessful();

        $this->assertLessThan(
            Storage::disk('public')->size('hero/banner.jpg'),
            Storage::disk('public')->size(ResponsiveImage::variantPath('hero/banner.jpg', 768)),
        );
    }

    public function test_the_generator_is_idempotent(): void
    {
        $this->jpeg('hero/banner.jpg');

        HeroBanner::create([
            'title' => ['en' => 'A Banner'],
            'image_path' => 'hero/banner.jpg',
            'is_active' => true,
            'overlay_opacity' => 40,
        ]);

        $this->forgetVariants('hero/banner.jpg');

        $this->artisan('images:responsive')->assertSuccessful();

        $this->artisan('images:responsive')
            ->expectsOutputToContain('Generated 0 file(s). 3 already present')
            ->assertSuccessful();
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->jpeg('hero/banner.jpg');

        HeroBanner::create([
            'title' => ['en' => 'A Banner'],
            'image_path' => 'hero/banner.jpg',
            'is_active' => true,
            'overlay_opacity' => 40,
        ]);

        $this->forgetVariants('hero/banner.jpg');

        $this->artisan('images:responsive', ['--dry-run' => true])->assertSuccessful();

        Storage::disk('public')->assertMissing(ResponsiveImage::variantPath('hero/banner.jpg', 768));
    }

    /** A row pointing at a file nobody uploaded is counted, not fatal. */
    public function test_a_missing_source_is_reported_rather_than_thrown(): void
    {
        HeroBanner::create([
            'title' => ['en' => 'A Banner'],
            'image_path' => 'hero/never-uploaded.jpg',
            'is_active' => true,
            'overlay_opacity' => 40,
        ]);

        $this->artisan('images:responsive')
            ->expectsOutputToContain('1 source image(s) missing from disk')
            ->assertSuccessful();
    }
}
