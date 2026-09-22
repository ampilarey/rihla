<?php

namespace Tests\Feature;

use App\Models\HeroBanner;
use App\Support\Brand;
use Database\Seeders\HeroBannerSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TripSeeder;
use Database\Seeders\WhySectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // The wine and gold palette, retired for violet and lemon chiffon.
        // Adding these is the whole point of this list, and not adding them
        // last time is why #DBD3CE survived in the error layout and #5B524D
        // in the guide PDF: the guard built to catch a half-finished palette
        // change was not told what had just been retired.
        '#8E2653' => 'wine-500, the previous primary',
        '#731F43' => 'wine-600',
        '#5B1835' => 'wine-700',
        '#D2A03C' => 'gold-500, the previous accent',
        '#E8C270' => 'gold-400',
        '#A87F2C' => 'gold-600',
        '#7A5A16' => 'gold-700',
        '#2E2621' => 'the previous ink',
        '#6B6159' => 'the previous ink-muted',
        '#FBF6EC' => 'the previous cream',
        '#F4EDDF' => 'the previous cream-deep',
        '#FFF9F4' => 'warm gray-50',
        '#FAF3EE' => 'warm gray-100',
        '#EDE6E1' => 'warm gray-200',
        '#DBD3CE' => 'warm gray-300',
        '#AAA19C' => 'warm gray-400',
        '#746B66' => 'warm gray-500',
        '#5B524D' => 'warm gray-600',
        '#483F39' => 'warm gray-700',
        '#2F2721' => 'warm gray-800',
        '#1F1610' => 'warm gray-900',
    ];

    /**
     * Files outside `resources/views` that still paint the brand themselves.
     *
     * `public/offline.html` is the reason this list exists. It is a complete
     * standalone page with its own inline stylesheet — no Tailwind, no
     * `Brand::` — and the service worker precaches it, so it is genuinely
     * served. It sat at the old wine-to-ink gradient through a palette change
     * that touched everything else, because the scan below only ever walked
     * the Blade directory.
     *
     * @var array<int, string>
     */
    private const PAINTED_ELSEWHERE = [
        'offline.html',
        'favicon.svg',
        'images/rihla-mark.svg',
        'images/rihla-mark-inverse.svg',
        'images/rihla-icon.svg',
        'images/rihla-icon-small.svg',
        'manifest.json',
    ];

    public function test_no_view_still_uses_a_retired_colour(): void
    {
        $offenders = [];

        $files = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $files[$file->getRelativePathname()] = $file->getPathname();
        }

        foreach (self::PAINTED_ELSEWHERE as $relative) {
            $path = public_path($relative);

            $this->assertFileExists($path,
                "public/{$relative} is listed as painting the brand itself but does not exist; "
                .'either restore it or take it off the list.');

            $files['public/'.$relative] = $path;
        }

        foreach ($files as $name => $path) {
            $contents = File::get($path);

            foreach (self::RETIRED as $hex => $what) {
                if (stripos($contents, $hex) === false) {
                    continue;
                }

                $offenders[] = "{$name}: {$hex} ({$what})";
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Pre-rebrand colours are still being painted:'], $offenders,
        )));
    }

    /**
     * A colour class that compiles to nothing is invisible, not wrong.
     *
     * `ink`, `cream`, `error`, `success` and `warning` were all defined
     * with a `DEFAULT`, so `text-ink` and `bg-cream` worked everywhere and
     * everybody wrote them. `wine` and `gold` were not, so `bg-wine`,
     * `text-wine`, `border-s-wine` and `border-s-gold` produced **no rule
     * at all** — and the failure is silent in the worst way: the element
     * renders, the markup is right, every assertion passes, and
     * `bg-wine px-3 py-1 text-white` is white text on no background.
     *
     * That was live on two screens. The tour leader's head count said
     * "3 missing" in white on cream, and the family portal's attendance
     * badge said "Not yet counted" the same way — on a telephone, at a
     * hotel desk in Makkah, to the two audiences least able to work out
     * what they were missing.
     *
     * So: any palette a view names without a shade has to have a DEFAULT.
     */
    public function test_no_view_names_a_colour_that_compiles_to_nothing(): void
    {
        $config = File::get(base_path('tailwind.config.js'));

        // Palette names declared as objects in the config, e.g. `wine: {`.
        preg_match_all('/^\s{6,10}([a-z][a-zA-Z0-9]*):\s*\{/m', $config, $found);

        $withDefault = [];

        foreach ($found[1] as $palette) {
            if (preg_match('/\b'.$palette.':\s*\{(.*?)\n\s*\},/s', $config, $body) !== 1) {
                continue;
            }

            $withDefault[$palette] = str_contains($body[1], 'DEFAULT:');
        }

        $this->assertNotEmpty($withDefault, 'No palettes were read out of tailwind.config.js.');

        // Every utility prefix that takes a colour and is used here.
        $prefixes = 'bg|text|border|border-s|border-e|border-t|border-b|ring|divide|fill|stroke|from|via|to|outline|shadow|accent|caret|decoration|placeholder';

        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $contents = File::get($file->getPathname());

            foreach ($withDefault as $palette => $hasDefault) {
                if ($hasDefault) {
                    continue;
                }

                // The class named bare — no shade, and not part of a longer
                // word such as `bg-wine-500` or `text-inkling`.
                $pattern = '/\b(?:'.$prefixes.')-'.$palette.'(?![\w-])/';

                if (preg_match($pattern, $contents) === 1) {
                    preg_match($pattern, $contents, $hit);
                    $offenders[] = $file->getRelativePathname().': '.$hit[0];
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These classes produce no CSS, so the element renders with no colour at all.',
                'Either give the palette a DEFAULT in tailwind.config.js or name a shade:'],
            $offenders,
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
            'title' => 'Umrah 2026',
        ]);

        $this->assertSame(Brand::WINE, $banner->fresh()->primary_cta_bg_color);
        $this->assertSame(Brand::CREAM, $banner->fresh()->subheading_color);
    }

    /**
     * A fresh database is the easy case. The hard one is a database that
     * already ran the last rebrand.
     *
     * `2026_09_18_170000_rebrand_stored_banner_colours` wrote its new values
     * as `Brand::WINE` and `Brand::CREAM`. It ran on test and on production
     * while those constants held `#8E2653` and `#FBF6EC`, so those rows are
     * stamped with the old palette and changing the constants does not reach
     * them. The test above still passed throughout, because `migrate:fresh`
     * in CI re-runs that migration with the *current* constants and never
     * sees the stamped state a deployed database is actually in.
     *
     * So this one reproduces the deployed state — old hexes in the rows —
     * and runs the follow-up migration over it.
     */
    public function test_colours_stamped_by_the_previous_rebrand_are_carried_forward(): void
    {
        $id = DB::table('hero_banners')->insertGetId([
            'title' => json_encode(['en' => 'Stamped by the last rebrand']),
            'primary_cta_bg_color' => '#8E2653',   // the old wine
            'subheading_color' => '#FBF6EC',       // the old cream
            'heading_color' => '#1a2b3c',          // an editor's own choice
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_09_21_090000_recolour_stored_brand_values.php'
        );
        $migration->up();

        $row = DB::table('hero_banners')->where('id', $id)->first();

        $this->assertSame(Brand::WINE, $row->primary_cta_bg_color,
            'A banner stamped with the old wine still renders in the retired brand.');

        $this->assertSame(Brand::CREAM, $row->subheading_color,
            'A banner stamped with the old cream still renders in the retired brand.');

        $this->assertSame('#1a2b3c', $row->heading_color,
            'A colour an editor chose deliberately was overwritten; this is a rebrand, not a veto.');
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
     *
     * This test used to assert that the two marks differed ONLY in the hull,
     * on the belief that the sails held against either ground. They did not:
     * measured, the old wine sail was 1.8:1 against ink and the old gold sail
     * 2.38:1 on white, both well under the 3:1 a graphic element needs — and
     * the sameness assertion could never have caught it, because it compared
     * the two files to each other rather than either one to its background.
     * It now checks what actually matters: identical geometry, and every fill
     * legible against the surface that variant is for.
     */
    public function test_the_logo_hull_is_visible_on_a_dark_surface(): void
    {
        $inverse = File::get(public_path('images/rihla-mark-inverse.svg'));

        $this->assertStringNotContainsString(Brand::INK, $inverse,
            'The dark-surface logo still paints its hull in the footer background colour.');

        $this->assertStringContainsString(Brand::CREAM, $inverse,
            'The dark-surface logo should carry a cream hull.');

        $light = File::get(public_path('images/rihla-mark.svg'));

        // Geometry is shared; only the fills may differ between the variants.
        $geometry = static fn (string $svg): array => (function () use ($svg): array {
            preg_match_all('/ d="([^"]+)"/', $svg, $m);

            return $m[1];
        })();

        $this->assertSame($geometry($light), $geometry($inverse),
            'The two logo variants have drifted apart; the artwork must be identical.');

        // The three fills, in document order: hull, fore sail, main sail.
        $fills = static function (string $svg): array {
            preg_match_all('/fill="(#[0-9A-Fa-f]{6})"/', $svg, $m);

            return $m[1];
        };

        foreach ([[$light, Brand::WHITE, 'light'], [$inverse, Brand::INK, 'dark']] as [$svg, $ground, $which]) {
            $painted = $fills($svg);

            $this->assertCount(3, $painted,
                "The {$which}-surface mark has ".count($painted).' fills; it is three shapes.');

            [$hull, $fore, $main] = $painted;

            // The hull and the main sail are the silhouette. If either of
            // those goes faint the mark stops reading as a dhoni at all, so
            // both still have to clear the 3:1 a shape needs.
            foreach (['hull' => $hull, 'main sail' => $main] as $part => $fill) {
                if (strcasecmp($fill, $ground) === 0) {
                    continue;   // the hull of the light mark IS the ink; it is the shape, not a shape on it
                }

                $this->assertGreaterThanOrEqual(3.0, $this->contrast($fill, $ground),
                    "The {$which}-surface mark paints its {$part} {$fill} on {$ground}, which is "
                    .round($this->contrast($fill, $ground), 2).':1 — under the 3:1 a shape needs to be seen.');
            }

            // The fore sail is the accent, and it is deliberately the same
            // gold as the primary call to action.
            //
            // It does not clear 3:1 on white — 1.49:1 — and no vivid yellow
            // can: 3:1 against white needs a relative luminance at or below
            // 0.30 and #EFD34D sits at 0.655, so the only golds that pass are
            // the drab ones. Rendered at 80px and at 34px it reads clearly
            // anyway, because a fully saturated hue separates from white in a
            // way a luminance ratio does not describe. That is a judgement
            // about this one shape on this one ground, not a licence: the
            // silhouette above still has to pass, and a logo is outside
            // WCAG 1.4.11 in the first place.
            //
            // So the value is pinned rather than measured. Changing it is
            // allowed; changing it silently is not.
            $this->assertSame('#EFD34D', strtoupper($fore),
                "The {$which}-surface mark's fore sail is {$fore}. It is the accent and it is "
                .'meant to match the primary button exactly, so it is asserted by value — if this '
                .'is a deliberate change, measure the new colour against both grounds and say so here.');
        }

        $this->assertStringContainsString('on="dark"', $this->footerMarkup(),
            'The footer does not ask for the dark-surface logo.');

        // And the rendered page actually serves it.
        $this->seed([SettingsSeeder::class, TripSeeder::class,
            HeroBannerSeeder::class, WhySectionSeeder::class]);

        $this->get('/en')
            ->assertOk()
            ->assertSee('rihla-mark-inverse.svg', false);
    }

    /**
     * The company name sits beside the mark as text, so it inherits the same
     * problem the hull had: on the footer it must not be painted in the
     * footer's own colour.
     *
     * The first version of the lockup got this wrong — `text-cream` had never
     * been compiled into the stylesheet, so the footer name rendered in the
     * default ink on an ink background. Invisible, and invisible to every
     * assertion that only checked the markup. A browser screenshot caught it.
     */
    public function test_the_logo_name_is_legible_on_both_surfaces(): void
    {
        $css = File::get(File::glob(public_path('build/assets/*.css'))[0]);

        foreach (['text-cream', 'text-ink'] as $class) {
            $this->assertStringContainsString('.'.$class, $css,
                "The compiled stylesheet has no .{$class} rule, so the logo name "
                .'falls back to whatever it inherits.');
        }

        $component = File::get(resource_path('views/components/brand-logo.blade.php'));

        $this->assertStringContainsString('text-cream', $component,
            'The dark lockup does not set a light colour for the name.');

        // And the rendered footer really carries it.
        $this->seed([SettingsSeeder::class, TripSeeder::class,
            HeroBannerSeeder::class, WhySectionSeeder::class]);

        $footer = $this->renderedFooter();

        $this->assertStringContainsString('text-cream', $footer,
            'The footer lockup does not render its name in cream.');
    }

    /** The footer as the browser receives it. */
    private function renderedFooter(): string
    {
        $html = $this->get('/en')->assertOk()->getContent();

        $start = strpos($html, '<footer');
        $end = strpos($html, '</footer>');

        return substr($html, $start, $end - $start);
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

    /**
     * A fixed button must be legible over anything it can float over.
     *
     * The floating WhatsApp, Call and Catalog buttons are `position: fixed`, so
     * they pass over every section of every page. Two of them are wine-filled,
     * and a wine circle over a wine section has **1.00:1** against its own
     * backdrop — the disc vanishes and the icon is left hanging in mid-air.
     * That is not a contrast ratio a text-colour check would ever catch, and it
     * was found in a phone screenshot of the live site, not by a test.
     *
     * The cream ring gives them an edge on wine (7.64:1) and is invisible on
     * cream, where the wine fill supplies its own. The Call button needs none:
     * its cream fill is already 7.64:1 against wine.
     */
    public function test_every_floating_button_keeps_an_edge_on_any_background(): void
    {
        $component = File::get(resource_path('views/components/whatsapp-fab.blade.php'));

        preg_match_all('/<a\s[^>]*class="([^"]*w-14[^"]*rounded-full[^"]*)"/s', $component, $matches);

        $this->assertNotEmpty($matches[1], 'No floating buttons found to check.');
        $this->assertCount(2, $matches[1],
            'Message us and Call us. The WhatsApp catalog button has gone — the site carries the trips now.');

        // All three carry the same treatment now. The Call button used to be
        // inverted — cream fill, thin gold-700 outline — which read as an
        // accident rather than a decision, and its icon was the faintest thing
        // on the screen at 5.90:1 against its own fill.
        foreach ($matches[1] as $classes) {
            // One is WhatsApp's, on WhatsApp's own green, because a recoloured
            // WhatsApp mark is a worse button — people recognise that circle
            // without reading anything. The rest of the site stays wine.
            $ours = ! str_contains($classes, 'bg-whatsapp');

            if ($ours) {
                $this->assertStringContainsString('bg-wine', $classes,
                    'Our own floating buttons share one treatment; a lone inverted one reads as a mistake.');
                $this->assertStringContainsString('text-cream', $classes,
                    'Cream on wine is 7.64:1.');
            }

            $this->assertMatchesRegularExpression(
                '/\b(ring-\d|border(-\d)?)\b/',
                $classes,
                'A wine-filled floating button has no ring or border, so it disappears '
                .'against a wine section — 1.00:1 against its own backdrop.',
            );

            $this->assertMatchesRegularExpression('/\b(ring|border)-cream\b/', $classes,
                'The edge must be cream; a wine edge on wine is the same defect.');
        }
    }
}
