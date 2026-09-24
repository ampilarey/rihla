<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\HeroBanner;
use App\Models\Package;
use App\Support\ResponsiveImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Responsive variants are removed as well as made — §10.1.
 *
 * Generation has existed since Phase 2. Deletion existed in exactly one
 * place, `HeroBannerController::deleteImage()`, so a **trip, package or
 * article** cover that was replaced or deleted left its three WebP files
 * on disk and always had.
 *
 * Nothing reported it, and nothing could: the site renders correctly
 * either way and the only symptom is a disk filling up. On cPanel that
 * allowance is fixed rather than elastic, and the failure when it runs
 * out is writes failing across the whole application — uploads, sessions,
 * the log, the SQLite database if it is being used.
 *
 * Named for the half it covers. `ResponsiveImagesTest` already owns
 * naming, generation and the srcset, per the AGENTS.md rule about a
 * second suite on one domain.
 */
class ResponsiveImageCleanupTest extends TestCase
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
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 95, 73, 138));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $bytes);
    }

    /** @return list<string> the variant paths that exist for a source */
    private function variantsOnDisk(string $path): array
    {
        return array_values(array_filter(
            array_map(
                fn (int $width): string => ResponsiveImage::variantPath($path, $width),
                ResponsiveImage::WIDTHS,
            ),
            fn (string $variant): bool => Storage::disk('public')->exists($variant),
        ));
    }

    // ── The helper ───────────────────────────────────────────────────────

    public function test_forget_removes_every_variant_and_says_how_many(): void
    {
        $this->jpeg('packages/cover.jpg');
        ResponsiveImage::generate('packages/cover.jpg');

        $this->assertCount(3, $this->variantsOnDisk('packages/cover.jpg'));

        $this->assertSame(3, ResponsiveImage::forget('packages/cover.jpg'));
        $this->assertSame([], $this->variantsOnDisk('packages/cover.jpg'));
    }

    /**
     * The original is the caller's to decide about. A record can be
     * removed while the file it pointed at is still wanted, and this has
     * no way to know which.
     */
    public function test_forget_leaves_the_original_alone(): void
    {
        $this->jpeg('packages/cover.jpg');
        ResponsiveImage::generate('packages/cover.jpg');

        ResponsiveImage::forget('packages/cover.jpg');

        $this->assertTrue(Storage::disk('public')->exists('packages/cover.jpg'));
    }

    /** The ordinary case for a record saved with no image at all. */
    public function test_forgetting_a_path_with_no_variants_is_harmless(): void
    {
        $this->assertSame(0, ResponsiveImage::forget('packages/never-existed.jpg'));
        $this->assertSame(0, ResponsiveImage::forget(''));
    }

    // ── Through the models that actually leak ────────────────────────────

    /**
     * The case that had never been cleaned up anywhere: a package cover
     * swapped for a different photograph.
     */
    public function test_replacing_a_cover_takes_the_old_variants_with_it(): void
    {
        $this->jpeg('packages/first.jpg');
        $this->jpeg('packages/second.jpg');

        $package = Package::factory()->create(['cover_image' => 'packages/first.jpg']);

        $this->assertCount(3, $this->variantsOnDisk('packages/first.jpg'));

        $package->update(['cover_image' => 'packages/second.jpg']);

        $this->assertSame([], $this->variantsOnDisk('packages/first.jpg'), 'The replaced cover left its variants behind.');
        $this->assertCount(3, $this->variantsOnDisk('packages/second.jpg'));
    }

    public function test_deleting_a_record_takes_its_variants_with_it(): void
    {
        $this->jpeg('articles/cover.jpg');

        $article = Article::factory()->create(['cover_image' => 'articles/cover.jpg']);

        $this->assertCount(3, $this->variantsOnDisk('articles/cover.jpg'));

        $article->delete();

        $this->assertSame([], $this->variantsOnDisk('articles/cover.jpg'));
    }

    /** The hero banner column is `image_path`, not `cover_image`. */
    public function test_a_hero_banner_is_cleaned_up_through_its_own_column(): void
    {
        $this->jpeg('hero/banner.jpg');

        // Created directly: there is no HeroBannerFactory, and adding one
        // for a single assertion would be a fixture to keep in step with a
        // model this test does not otherwise exercise.
        $banner = HeroBanner::create([
            'title' => ['en' => 'Ramadan Umrah'],
            'image_path' => 'hero/banner.jpg',
        ]);

        $this->assertCount(3, $this->variantsOnDisk('hero/banner.jpg'));

        $banner->delete();

        $this->assertSame([], $this->variantsOnDisk('hero/banner.jpg'));
    }

    /**
     * An edit that does not touch the image must not disturb it —
     * otherwise every price change re-encodes three photographs, or worse,
     * deletes the variants of a cover nothing replaced.
     */
    public function test_an_unrelated_edit_leaves_the_variants_standing(): void
    {
        $this->jpeg('packages/cover.jpg');

        $package = Package::factory()->create(['cover_image' => 'packages/cover.jpg']);

        $this->assertCount(3, $this->variantsOnDisk('packages/cover.jpg'));

        $package->update(['sort_order' => 7]);

        $this->assertCount(3, $this->variantsOnDisk('packages/cover.jpg'));
    }

    /** A record with no image deletes without touching anything. */
    public function test_deleting_a_record_with_no_cover_is_harmless(): void
    {
        $package = Package::factory()->create(['cover_image' => null]);

        $package->delete();

        $this->assertTrue(true);
    }
}
