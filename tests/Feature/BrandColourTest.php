<?php

namespace Tests\Feature;

use App\Models\HeroBanner;
use App\Support\Brand;
use Database\Seeders\HeroBannerSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TripSeeder;
use Database\Seeders\WhySectionSeeder;
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

    /**
     * Tailwind's grey scale is built for white backgrounds, and the footer is
     * ink. Several steps of it are illegible there, and the footer was using
     * two of them.
     *
     * The tagline was `text-gray-600` — 1.96:1 against `bg-ink`, which is not
     * "dim", it is unreadable — and the divider above it was `border-gray-700`
     * at 1.44:1, an invisible line. Both shipped, and neither showed up in the
     * accessibility pass, which checked structure rather than colour.
     */
    public function test_footer_text_is_legible_on_the_ink_background(): void
    {
        $footer = $this->footerMarkup();

        // Tailwind's default scale, which is what these classes resolve to.
        $scale = [
            '300' => '#D1D5DB', '400' => '#9CA3AF', '500' => '#6B7280',
            '600' => '#4B5563', '700' => '#374151', '800' => '#1F2937',
        ];

        $offenders = [];

        preg_match_all('/\btext-gray-(\d{3})\b/', $footer, $matches);

        foreach (array_unique($matches[1]) as $step) {
            if (! isset($scale[$step])) {
                continue;
            }

            $ratio = $this->contrast($scale[$step], Brand::INK);

            if ($ratio < 4.5) {
                $offenders[] = sprintf('text-gray-%s (%s) is %.2f:1 on bg-ink; AA needs 4.5:1',
                    $step, $scale[$step], $ratio);
            }
        }

        // A divider is not text, so WCAG asks 3:1 of it rather than 4.5:1.
        preg_match_all('/\bborder-gray-(\d{3})\b/', $footer, $borders);

        foreach (array_unique($borders[1]) as $step) {
            if (! isset($scale[$step])) {
                continue;
            }

            $ratio = $this->contrast($scale[$step], Brand::INK);

            if ($ratio < 3.0) {
                $offenders[] = sprintf('border-gray-%s (%s) is %.2f:1 on bg-ink; a visible line needs 3:1',
                    $step, $scale[$step], $ratio);
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Footer colours that cannot be seen against bg-ink:'],
            $offenders,
        )));
    }

    /**
     * The logo hull is ink. So is the footer. On the footer the hull vanished
     * and the logo rendered as two sails floating above nothing — visible
     * immediately on a phone, and invisible to every test that only asked
     * whether the logo was present.
     */
    public function test_the_logo_hull_is_visible_on_a_dark_surface(): void
    {
        $inverse = File::get(public_path('images/rihla-mark-inverse.svg'));

        $this->assertStringNotContainsString(Brand::INK, $inverse,
            'The dark-surface logo still paints its hull in the footer background colour.');

        $this->assertStringContainsString(Brand::CREAM, $inverse,
            'The dark-surface logo should carry a cream hull.');

        // Same geometry, different hull: only the fill may differ.
        $light = File::get(public_path('images/rihla-mark.svg'));

        $this->assertSame(
            str_replace(Brand::INK, 'HULL', $light),
            str_replace(Brand::CREAM, 'HULL', $inverse),
            'The two logo variants have drifted apart; only the hull colour should differ.',
        );

        $this->assertStringContainsString('on="dark"', $this->footerMarkup(),
            'The footer does not ask for the dark-surface logo.');

        // And the rendered page actually serves it.
        $this->seed([SettingsSeeder::class, TripSeeder::class,
            HeroBannerSeeder::class, WhySectionSeeder::class]);

        $this->get('/en')
            ->assertOk()
            ->assertSee('rihla-mark-inverse.svg', false);
    }

    /** The footer block of the main layout. */
    private function footerMarkup(): string
    {
        $layout = File::get(resource_path('views/layouts/app.blade.php'));

        $start = strpos($layout, '<footer');
        $end = strpos($layout, '</footer>');

        $this->assertNotFalse($start, 'No footer in the layout.');

        // Blade comments are stripped first. The comment recording *why*
        // text-gray-600 was replaced names the class, and an earlier version
        // of this test dutifully reported it as still in use.
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', substr($layout, $start, $end - $start));
    }
}
