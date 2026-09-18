<?php

namespace Tests\Feature;

use App\Models\HeroBanner;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The palette reached the stylesheets and stopped there.
 *
 * Hero banners and homepage "why" sections keep their colours in the
 * database, so when a row has none set a hex literal in the Blade template
 * decides. Those literals were missed: the live homepage went on rendering a
 * blue call-to-action, the trips tabs and gallery filters stayed green, and
 * the admin colour pickers offered the old palette as their starting value —
 * so choosing "the default" put blue back.
 *
 * Nothing compared the rendered colours against the brand, so none of it
 * showed up until someone looked at the page.
 */
class BrandColourTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hex values from before the rebrand. Any of these appearing in a view
     * means a component was left behind.
     *
     * The neutral greys in the guide's @media print block are deliberately
     * absent from this list: print output is greyscale and carries no brand,
     * so recolouring it would gain nothing on paper.
     *
     * @var array<string, string>
     */
    private const RETIRED = [
        '#0ea5e9' => 'sky-500, the old primary',
        '#1C9FE2' => 'the original brand blue',
        '#2563eb' => 'blue-600',
        '#0e7a57' => 'the old green used for active tabs',
        '#C39A3A' => 'the old gold',
        '#1f2937' => 'gray-800, a cool neutral',
        '#6b7280' => 'gray-500, a cool neutral',
        '#374151' => 'gray-700, a cool neutral',
    ];

    public function test_no_view_still_uses_a_retired_colour(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $contents = File::get($file->getPathname());

            foreach (self::RETIRED as $hex => $what) {
                if (stripos($contents, $hex) === false) {
                    continue;
                }

                $offenders[] = $file->getRelativePathname().": {$hex} ({$what})";
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Views still contain pre-rebrand colours:'], $offenders,
        )));
    }

    /**
     * The constants exist so a palette change cannot leave a component
     * behind again; they are only useful while they match the source.
     */
    public function test_the_constants_match_the_tailwind_config(): void
    {
        $config = File::get(base_path('tailwind.config.js'));

        foreach ([
            Brand::WINE => 'wine-500',
            Brand::GOLD => 'gold-500',
            Brand::INK => 'ink',
            Brand::INK_MUTED => 'ink.muted',
            Brand::CREAM => 'cream',
            Brand::BORDER => 'gray-300',
        ] as $hex => $token) {
            $this->assertStringContainsString(
                $hex,
                $config,
                "Brand::* carries {$hex} for {$token}, which tailwind.config.js does not define.",
            );
        }
    }

    /**
     * A banner created from now on must arrive in the current brand. The
     * column default is what decides that, and it was still sky blue.
     */
    public function test_a_new_banner_defaults_to_the_brand(): void
    {
        $this->assertTrue(Schema::hasColumn('hero_banners', 'primary_cta_bg_color'));

        $banner = HeroBanner::create([
            'locale' => 'en',
            'title' => 'Umrah 2026',
        ]);

        $this->assertSame(Brand::WINE, $banner->fresh()->primary_cta_bg_color);
        $this->assertSame(Brand::CREAM, $banner->fresh()->subheading_color);
    }

    /**
     * White on the primary is the single most repeated colour pairing on the
     * site, so it is the one worth asserting rather than assuming.
     */
    public function test_the_primary_button_meets_contrast(): void
    {
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrast(Brand::WINE, Brand::WHITE),
            'White on the primary colour is below WCAG AA for body text.',
        );

        // Gold is a light accent: it must carry ink, never white.
        $this->assertGreaterThanOrEqual(4.5, $this->contrast(Brand::GOLD, Brand::INK));
        $this->assertLessThan(4.5, $this->contrast(Brand::GOLD, Brand::WHITE));
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(
            static function (string $pair): float {
                $channel = hexdec($pair) / 255;

                return $channel <= 0.03928
                    ? $channel / 12.92
                    : (($channel + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2),
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
