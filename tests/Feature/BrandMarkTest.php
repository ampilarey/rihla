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

    /**
     * The only colours that may appear in brand artwork.
     *
     * Two golds, deliberately. The guide's lemon chiffon is legible on ink
     * (13.73:1) and invisible on cream (1.05:1); the dark one is the reverse. A mark is
     * cut for one ground or the other, so each uses the gold that works on
     * its own — see BrandColourTest, which measures every fill against the
     * surface its variant is for.
     */
    private const PALETTE = ['#5F498A', '#9481BA', '#FEF9CD', '#EFD34D', '#A88C1F', '#2E2245'];

    /**
     * Ultra violet, the field every app and browser icon is cut on.
     *
     * It was cream until the icon set moved to the brand pair. The two guide
     * colours — lemon chiffon #FEF9CD and ultra violet #5F498A — are 7.00:1
     * apart, which is AAA for one shape on the other and hopeless for two
     * shapes on a third: that needs about 9:1, so no ground shows both. The
     * icons therefore use them as field and figure rather than as two sails,
     * which is the one arrangement where both are at full strength on every
     * browser chrome, light or dark.
     *
     * The inline mark in `images/rihla-mark.svg` still uses the two-gold
     * split described above PALETTE, because it has no field of its own —
     * it sits directly on the page.
     *
     * Not the manifest's `background_color`, which stays cream: that paints
     * the splash screen behind the icon, not the icon.
     */
    private const ICON_FIELD = [95, 73, 138];

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
            $allowed = array_merge(self::PALETTE, ['#FFFDF0']);

            $this->assertSame([], array_values(array_diff($found, $allowed)),
                "{$file} uses colours outside the palette: ".implode(', ', array_diff($found, $allowed)));
        }
    }

    /**
     * The share card is the logo most people see, and nothing was watching it.
     *
     * `public/images/rihla-social.png` is what WhatsApp and Facebook render
     * when somebody pastes a link — for a business that sells through
     * WhatsApp, it is seen far more often than the header. It is a raster, so
     * the palette swap could not reach it, and no test sampled it: it went on
     * showing a wine-and-gold dhoni on the retired cream long after every
     * other surface had moved. `BrandColourTest` scans text; a PNG needs its
     * pixels read.
     */
    public function test_the_share_card_carries_the_current_brand(): void
    {
        $path = public_path('images/rihla-social.png');

        $this->assertFileExists($path, 'There is no social preview image.');

        $image = imagecreatefrompng($path);

        $this->assertSame(1200, imagesx($image),
            'Open Graph wants 1200x630; anything else is recropped by the platform.');
        $this->assertSame(630, imagesy($image));

        $retired = ['#8E2653', '#D2A03C', '#2E2621', '#FBF6EC', '#F4EDDF'];
        $found = [];

        for ($y = 0; $y < 630; $y += 2) {
            for ($x = 0; $x < 1200; $x += 2) {
                $rgb = imagecolorat($image, $x, $y);
                $hex = sprintf('#%02X%02X%02X', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);

                if (in_array($hex, $retired, true)) {
                    $found[$hex] = true;
                }
            }
        }

        imagedestroy($image);

        $this->assertSame([], array_keys($found),
            'The share card still paints retired brand colours: '.implode(', ', array_keys($found)));

        // The retired list is not enough, and this is how it was found out.
        //
        // When the mark's fore sail moved from #A88C1F to #EFD34D, the card
        // kept 4,768 pixels of the old gold and this test stayed green: the
        // outgoing colour was not retired from the palette, it had simply
        // stopped being in the logo. A card can therefore disagree with the
        // mark it is a picture of while every assertion passes.
        //
        // So the card is now checked against the mark rather than against a
        // list of the dead. Every colour covering a tenth of a percent of it
        // has to be one the SVG declares, or the field it sits on. The
        // threshold is what keeps antialiasing along the sail edges out of
        // it — those blends are a couple of hundred pixels each.
        preg_match_all('/fill="(#[0-9A-Fa-f]{6})"/', File::get(public_path('images/rihla-mark.svg')), $m);

        $allowed = array_map('strtoupper', array_merge($m[1], ['#FFFDF0', '#2E2245']));
        $counts = [];

        for ($y = 0; $y < 630; $y++) {
            for ($x = 0; $x < 1200; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $hex = sprintf('#%02X%02X%02X', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
                $counts[$hex] = ($counts[$hex] ?? 0) + 1;
            }
        }

        $floor = (int) (1200 * 630 * 0.001);
        $strangers = [];

        foreach ($counts as $hex => $pixels) {
            if ($pixels >= $floor && ! in_array($hex, $allowed, true)) {
                $strangers[] = "{$hex} ({$pixels}px)";
            }
        }

        $this->assertSame([], $strangers,
            'The share card paints colours the mark does not: '.implode(', ', $strangers)
            .'. It is a picture of the logo, so every colour in it should come from the logo.');
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
                        if (abs($channel - self::ICON_FIELD[$i]) > 12) {
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
            $this->assertSame(self::ICON_FIELD, [($corner >> 16) & 0xFF, ($corner >> 8) & 0xFF, $corner & 0xFF],
                "{$file} is not cut on the brand field.");

            imagedestroy($image);
        }
    }

    /**
     * The icon masters carry the two guide colours and nothing else.
     *
     * `images/rihla-icon.svg` is the geometry every raster below is cut from,
     * so a third colour creeping in here reaches the tab bar, the home screen
     * and the install prompt at once.
     */
    public function test_the_icon_master_is_the_brand_pair(): void
    {
        foreach (['images/rihla-icon.svg', 'images/rihla-icon-small.svg', 'favicon.svg'] as $file) {
            preg_match_all('/#[0-9A-Fa-f]{6}/', File::get(public_path($file)), $matches);

            $found = array_values(array_unique(array_map('strtoupper', $matches[0])));

            sort($found);

            $this->assertSame(['#5F498A', '#FEF9CD'], $found,
                "{$file} should be lemon chiffon and ultra violet only; it has ".implode(', ', $found));
        }
    }

    /**
     * favicon.ico shipped the retired maroon brand and nothing noticed.
     *
     * The only assertion this file ever had was `filesize() > 0`. So while
     * every other icon was recut for the violet palette, the .ico kept a
     * wine sail and an old gold one, and went on being the icon every
     * browser with no SVG support showed — which is the icon in most
     * bookmark bars. A size check is not a guard: it passes for any bytes at
     * all, including last year's artwork.
     *
     * An .ico is a directory of images rather than one image, so each entry
     * has to be decoded on its own. GD cannot open the container, but every
     * entry written here is a PNG, and `imagecreatefromstring` reads those.
     */
    public function test_the_ico_carries_the_current_brand(): void
    {
        $bytes = File::get(public_path('favicon.ico'));

        $count = unpack('v', substr($bytes, 4, 2))[1];

        $this->assertGreaterThanOrEqual(3, $count,
            'favicon.ico holds one size, so small tab icons are downsampled from a large one.');

        $retired = ['#8E2653', '#731F43', '#D2A03C', '#A87F2C', '#2E2621', '#FBF6EC', '#C39A3A', '#1C9FE2'];
        $checked = 0;
        $found = [];
        $brandSeen = false;

        for ($i = 0; $i < $count; $i++) {
            $entry = substr($bytes, 6 + $i * 16, 16);
            [$length, $offset] = array_values(unpack('V2', substr($entry, 8, 8)));

            $payload = substr($bytes, $offset, $length);

            // A BMP-encoded entry would need its own decoder; the PNG ones
            // carry the same artwork, so checking those is enough.
            if (! str_starts_with($payload, "\x89PNG\r\n\x1a\n")) {
                continue;
            }

            $image = imagecreatefromstring($payload);

            $this->assertNotFalse($image, "Entry {$i} of favicon.ico is not a readable image.");

            $width = imagesx($image);
            $height = imagesy($image);

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $rgb = imagecolorat($image, $x, $y);
                    $hex = sprintf('#%02X%02X%02X', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);

                    if (in_array($hex, $retired, true)) {
                        $found[$hex] = true;
                    }

                    if ($hex === '#5F498A' || $hex === '#FEF9CD') {
                        $brandSeen = true;
                    }
                }
            }

            imagedestroy($image);
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'No entry of favicon.ico could be decoded.');

        $this->assertSame([], array_keys($found),
            'favicon.ico still paints retired brand colours: '.implode(', ', array_keys($found)));

        $this->assertTrue($brandSeen,
            'favicon.ico carries neither brand colour, so it is not the current mark.');
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
     * The recoloured master moves those two hues and nothing else. It is no
     * longer served — the dhoni is the logo — but it is kept for print and
     * for anyone who re-adopts it, and it stays palette-correct.
     */
    public function test_the_retired_wordmark_carries_no_pre_rebrand_colour(): void
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
                .'the palette calls for ink #2E2245.');
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

    /** Both wordmarks stay in the repository, retired rather than deleted. */
    public function test_the_wordmark_artwork_is_preserved(): void
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

    /** The dhoni is the logo now, in every place the wordmark used to be. */
    public function test_the_mark_is_the_logo_everywhere(): void
    {
        $this->seedSite();

        foreach (['/en', '/dv', '/en/trips', '/login'] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee('images/rihla-mark.svg', false);
        }
    }

    /**
     * One vector file covers every size, from the 16-pixel favicon to the
     * header. The wordmark it replaced needed six rasters, and before that a
     * single 1.44 MB PNG.
     */
    public function test_no_page_serves_the_retired_wordmark(): void
    {
        $this->seedSite();

        foreach (['/en', '/dv', '/en/trips', '/en/guide', '/login'] as $page) {
            $this->get($page)->assertDontSee('rihla-logo', false);
        }
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
