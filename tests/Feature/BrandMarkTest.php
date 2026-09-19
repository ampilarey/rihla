<?php

namespace Tests\Feature;

use Database\Seeders\HeroBannerSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TripSeeder;
use Database\Seeders\WhySectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The dhoni mark, and the icons cut from it.
 *
 * docs/BRAND.md §4 recorded "Dhoni alone (two-sail)" as the preferred
 * direction but noted "No SVG master anywhere in the repository". There is
 * one now: public/images/rihla-mark.svg, lifted from the vector paths of the
 * palette document rather than traced, so the geometry is the designer's.
 *
 * It is the app and browser icon only. The header keeps the Rihla Travels
 * wordmark — the mark carries no name, and a visitor arriving from search
 * needs to see one.
 */
class BrandMarkTest extends TestCase
{
    // Without this the HTTP assertions below hit a database with no tables,
    // get Laravel's debug page, and pass against it — that page echoes the
    // source of the failing test, so asserting on a filename in this very
    // file succeeded while /en was returning 500.
    use RefreshDatabase;

    /** The only colours that may appear in brand artwork. */
    private const PALETTE = ['#8E2653', '#D2A03C', '#2E2621'];

    /** Cream, the field every icon is cut on. Matches the manifest. */
    private const FIELD = [251, 246, 236];

    public function test_the_mark_is_a_vector_master(): void
    {
        $path = public_path('images/rihla-mark.svg');

        $this->assertFileExists($path, 'The SVG master is missing.');

        $svg = File::get($path);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('viewBox', $svg,
            'Without a viewBox the mark cannot scale.');
        $this->assertLessThan(4096, strlen($svg),
            'The mark is three shapes; anything this large has been traced rather than drawn.');
    }

    /** Brand artwork may not introduce a colour the palette does not define. */
    public function test_the_mark_uses_only_brand_colours(): void
    {
        foreach (['images/rihla-mark.svg', 'favicon.svg'] as $file) {
            preg_match_all('/#[0-9A-Fa-f]{6}/', File::get(public_path($file)), $matches);

            $found = array_unique(array_map('strtoupper', $matches[0]));
            $allowed = array_merge(self::PALETTE, ['#FBF6EC']);

            $this->assertSame([], array_values(array_diff($found, $allowed)),
                "{$file} uses colours outside the palette: ".implode(', ', array_diff($found, $allowed)));
        }
    }

    /** Every icon the layout or the manifest names must actually exist. */
    public function test_every_declared_icon_exists_and_is_the_size_it_claims(): void
    {
        $expected = [
            'favicon-16x16.png' => 16,
            'favicon-32x32.png' => 32,
            'apple-touch-icon.png' => 180,
            'images/icon-192.png' => 192,
            'images/icon-512.png' => 512,
            'images/icon-maskable-192.png' => 192,
            'images/icon-maskable-512.png' => 512,
        ];

        foreach ($expected as $file => $size) {
            $path = public_path($file);

            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path), "{$file} is an empty file.");

            [$width, $height] = getimagesize($path);

            $this->assertSame($size, $width, "{$file} is {$width}px wide, not {$size}.");
            $this->assertSame($size, $height, "{$file} is not square.");
        }

        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')),
            'favicon.ico is an empty file, which is how it shipped before.');
    }

    /**
     * Android crops a maskable icon to a circle or a squircle and keeps only
     * the middle 80%. Anything in the corners is liable to be cut off, so the
     * artwork has to stay inside that safe zone — otherwise the dhoni loses a
     * sail on someone's home screen and nobody finds out.
     */
    public function test_maskable_icons_keep_the_artwork_inside_the_safe_zone(): void
    {
        foreach (['images/icon-maskable-192.png', 'images/icon-maskable-512.png'] as $file) {
            $image = imagecreatefrompng(public_path($file));
            $size = imagesx($image);
            $centre = $size / 2;
            $safeRadius = $size * 0.4;   // the middle 80%, as a radius

            $outside = 0;

            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if (sqrt(($x - $centre) ** 2 + ($y - $centre) ** 2) <= $safeRadius) {
                        continue;
                    }

                    $rgb = imagecolorat($image, $x, $y);
                    $pixel = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];

                    // Allow for the resampler's edge blending.
                    foreach ($pixel as $i => $channel) {
                        if (abs($channel - self::FIELD[$i]) > 12) {
                            $outside++;
                            break;
                        }
                    }
                }
            }

            imagedestroy($image);

            $this->assertSame(0, $outside,
                "{$file} has {$outside} pixels of artwork outside the maskable safe zone; "
                .'Android will crop them.');
        }
    }

    /** An icon that vanishes on a dark browser theme is not an icon. */
    public function test_icons_are_cut_on_the_brand_field_rather_than_transparency(): void
    {
        foreach (['favicon-32x32.png', 'images/icon-512.png'] as $file) {
            $image = imagecreatefrompng(public_path($file));

            $corner = imagecolorat($image, 0, 0);
            $alpha = ($corner >> 24) & 0x7F;

            $this->assertSame(0, $alpha, "{$file} has a transparent corner.");
            $this->assertSame(self::FIELD, [($corner >> 16) & 0xFF, ($corner >> 8) & 0xFF, $corner & 0xFF],
                "{$file} is not cut on the cream field.");

            imagedestroy($image);
        }
    }

    public function test_the_layout_offers_the_vector_favicon_first(): void
    {
        $this->seedSite();

        $response = $this->get('/en')->assertOk();
        $html = $response->getContent();

        $svgAt = strpos($html, 'rel="icon" type="image/svg+xml"');
        $icoAt = strpos($html, 'type="image/x-icon"');

        $this->assertNotFalse($svgAt, 'No SVG favicon is offered.');
        $this->assertNotFalse($icoAt, 'No .ico fallback is offered.');
        $this->assertLessThan($icoAt, $svgAt,
            'The .ico is declared before the SVG, so browsers that support both take the raster.');
    }

    /**
     * The wordmark was the last thing on the site still wearing the
     * pre-rebrand scheme.
     *
     * It is drawn in #097EDD bright blue and pure #000000 black — neither in
     * the palette — while everything around it had moved to wine, gold and
     * ink. It sat at the top of every page looking exactly as it always had,
     * which is why "still I see the old logo" was the correct reading of a
     * site that had otherwise changed completely.
     *
     * The recoloured master moves those two hues and nothing else.
     */
    public function test_the_served_wordmark_carries_no_pre_rebrand_colour(): void
    {
        foreach ([200, 400, 600] as $width) {
            $path = public_path("images/rihla-logo-brand-{$width}.png");

            $this->assertFileExists($path);

            $image = imagecreatefrompng($path);
            $w = imagesx($image);
            $h = imagesy($image);

            $blue = 0;
            $pureBlack = 0;

            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    [$r, $g, $b, $a] = $this->pixel($image, $x, $y);

                    if ($a > 100) {
                        continue;   // effectively transparent
                    }

                    // The old calligraphy blue, #097EDD, and anything near it.
                    if ($b > 150 && $b - $r > 60 && $b - $g > 40) {
                        $blue++;
                    }

                    if ($r < 12 && $g < 12 && $b < 12) {
                        $pureBlack++;
                    }
                }
            }

            imagedestroy($image);

            $this->assertSame(0, $blue,
                "rihla-logo-brand-{$width}.png still has {$blue} pixels of pre-rebrand blue.");
            $this->assertSame(0, $pureBlack,
                "rihla-logo-brand-{$width}.png still has {$pureBlack} pixels of pure black; "
                .'the palette calls for ink #2E2621.');
        }
    }

    /**
     * Read one pixel as RGBA, whatever the PNG's colour type.
     *
     * The served wordmarks are quantised to a 64-entry palette, which is what
     * takes them under 4 KB. On a palette image imagecolorat() returns the
     * palette *index*, not a packed colour — so shifting it as though it were
     * one reports every pixel as near-black. An earlier version of the test
     * above did exactly that and failed against perfectly good artwork.
     *
     * @return array{int, int, int, int}
     */
    private function pixel(\GdImage $image, int $x, int $y): array
    {
        $at = imagecolorat($image, $x, $y);

        if (imageistruecolor($image)) {
            return [($at >> 16) & 0xFF, ($at >> 8) & 0xFF, $at & 0xFF, ($at >> 24) & 0x7F];
        }

        $colour = imagecolorsforindex($image, $at);

        return [$colour['red'], $colour['green'], $colour['blue'], $colour['alpha']];
    }

    /** The untouched original stays in the repository for reference. */
    public function test_the_original_artwork_is_preserved_alongside_the_recolour(): void
    {
        $original = public_path('images/rihla-logo.png');

        $this->assertFileExists($original, 'The original wordmark has been deleted.');

        [$width, $height] = getimagesize($original);

        $this->assertSame(6250, $width);
        $this->assertSame(2976, $height);

        $recoloured = public_path('images/rihla-logo-brand.png');

        $this->assertFileExists($recoloured);
        $this->assertSame([6250, 2976], array_slice(getimagesize($recoloured), 0, 2),
            'The recolour changed the artwork geometry; it should only change hues.');
    }

    /** The wordmark is still the logo. The mark did not replace it. */
    public function test_the_header_still_carries_the_wordmark(): void
    {
        $this->seedSite();

        $this->get('/en')
            ->assertOk()
            ->assertSee('rihla-logo-brand-400.png', false);
    }

    private function seedSite(): void
    {
        $this->seed([
            SettingsSeeder::class,
            TripSeeder::class,
            HeroBannerSeeder::class,
            WhySectionSeeder::class,
        ]);
    }
}
