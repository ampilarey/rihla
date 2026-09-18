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
use Tests\TestCase;

/**
 * Structural accessibility, checked against the rendered DOM.
 *
 * §10.2 of the upgrade plan calls for WCAG 2.2 AA. Colour contrast was done
 * in §4.5 and is covered by BrandColourTest; this is the other half, and it
 * is the half that cannot be checked by reading a palette.
 *
 * Everything asserted here was failing when the test was written:
 *
 *  - 105 `<svg>` elements were exposed to assistive tech with no name, 117 of
 *    them on one render of the guide page.
 *  - The three contact CTAs in the header carry their labels in a
 *    `hidden sm:inline` span. Tailwind's `hidden` is `display: none`, which
 *    takes text out of the accessibility tree as well as off the screen — so
 *    below the `sm` breakpoint, on the phones most of this site's visitors
 *    use, they were three unlabelled icons.
 *  - Three social links on the contact page contained nothing but an icon.
 *  - The trips page's tabs had no roles, no selected state and no keyboard
 *    handling: three unrelated buttons, with no way to tell which was active.
 *  - Nothing in the header said which page you were on, in markup or in ink.
 *  - Headings skipped from h1 to h3 on three pages.
 */
class AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Every public page, in both languages. */
    private const PAGES = [
        '/en', '/en/trips', '/en/gallery', '/en/social', '/en/contact', '/en/guide',
        '/dv', '/dv/trips', '/dv/gallery', '/dv/social', '/dv/contact', '/dv/guide',
    ];

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
        $html = $this->get($path)->getContent();

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /**
     * An icon with no name is announced as "graphic" or skipped, depending on
     * the reader. Either way it is noise: every icon here sits beside text or
     * inside a labelled control.
     */
    public function test_decorative_icons_are_hidden_from_assistive_technology(): void
    {
        $exposed = [];

        foreach (self::PAGES as $page) {
            $x = $this->xpath($page);

            $total = $x->query('//*[local-name()="svg"]')->length;
            $hidden = $x->query('//*[local-name()="svg"][@aria-hidden="true"]')->length;

            if ($total > $hidden) {
                $exposed[] = "{$page}: ".($total - $hidden)." of {$total}";
            }
        }

        $this->assertSame([], $exposed, implode("\n", array_merge(
            ['Icons are exposed to screen readers with no accessible name:'],
            $exposed,
        )));
    }

    /**
     * A link or button with no accessible name is unusable by anyone who
     * cannot see it, and unspeakable by anyone using voice control.
     */
    public function test_every_link_and_button_has_an_accessible_name(): void
    {
        $unnamed = [];

        foreach (self::PAGES as $page) {
            $x = $this->xpath($page);
            $doc = $x->document;

            foreach ($x->query('//a[@href] | //button') as $el) {
                $name = trim($el->textContent).$el->getAttribute('aria-label').$el->getAttribute('title');

                // An image's alt text names its link.
                foreach ($x->query('.//img', $el) as $img) {
                    $name .= trim($img->getAttribute('alt'));
                }

                if (trim($name) === '') {
                    $unnamed[] = $page.': '.substr(preg_replace('/\s+/', ' ', $doc->saveHTML($el)), 0, 100);
                }
            }
        }

        $this->assertSame([], $unnamed, implode("\n", array_merge(
            ['Controls with no accessible name:'],
            $unnamed,
        )));
    }

    /**
     * Tailwind's `hidden` is `display: none`, which removes text from the
     * accessibility tree. A control whose only label is inside one has no
     * name at the widths where that class applies.
     */
    public function test_a_control_labelled_only_below_a_breakpoint_is_labelled_at_every_width(): void
    {
        $bare = [];

        foreach (self::PAGES as $page) {
            $x = $this->xpath($page);
            $doc = $x->document;

            foreach ($x->query('//a[@href] | //button') as $el) {
                if ($el->getAttribute('aria-label') !== '' || $el->getAttribute('title') !== '') {
                    continue;
                }

                $responsive = $x->query('.//*[contains(@class, "hidden sm:") or contains(@class, "hidden md:")]', $el);

                if ($responsive->length === 0) {
                    continue;
                }

                $visible = trim($el->textContent);

                foreach ($responsive as $span) {
                    $visible = trim(str_replace(trim($span->textContent), '', $visible));
                }

                if ($visible === '') {
                    $bare[] = $page.': '.substr(preg_replace('/\s+/', ' ', $doc->saveHTML($el)), 0, 100);
                }
            }
        }

        $this->assertSame([], $bare, implode("\n", array_merge(
            ['These controls lose their only label below a breakpoint:'],
            $bare,
        )));
    }

    /** Headings are a document outline, and skipping a level breaks it. */
    public function test_heading_levels_do_not_skip(): void
    {
        $problems = [];

        foreach (self::PAGES as $page) {
            $x = $this->xpath($page);

            $levels = [];

            foreach ($x->query('//h1|//h2|//h3|//h4|//h5|//h6') as $h) {
                $levels[] = [(int) substr($h->nodeName, 1), trim(preg_replace('/\s+/', ' ', $h->textContent))];
            }

            $h1 = count(array_filter($levels, fn ($l) => $l[0] === 1));

            if ($h1 !== 1) {
                $problems[] = "{$page}: {$h1} h1 elements, expected exactly 1";
            }

            $previous = 0;

            foreach ($levels as [$level, $text]) {
                if ($previous !== 0 && $level > $previous + 1) {
                    $problems[] = "{$page}: h{$previous} → h{$level} at \"".substr($text, 0, 40).'"';
                }

                $previous = $level;
            }
        }

        $this->assertSame([], $problems, implode("\n", array_merge(
            ['Heading outline problems:'],
            $problems,
        )));
    }

    /** Two elements sharing an id break every aria-controls and label-for. */
    public function test_no_page_has_a_duplicate_id(): void
    {
        $duplicates = [];

        foreach (self::PAGES as $page) {
            $ids = [];

            foreach ($this->xpath($page)->query('//*[@id]') as $el) {
                $ids[] = $el->getAttribute('id');
            }

            foreach (array_count_values($ids) as $id => $count) {
                if ($count > 1) {
                    $duplicates[] = "{$page}: #{$id} appears {$count} times";
                }
            }
        }

        $this->assertSame([], $duplicates, implode("\n", array_merge(
            ['Duplicate ids:'],
            $duplicates,
        )));
    }

    /**
     * Nothing in the header said which page you were on — every item looked
     * identical everywhere, and no markup said otherwise either.
     */
    public function test_the_header_marks_the_page_you_are_on(): void
    {
        $cases = [
            '/en/trips' => 'Trips',
            '/en/gallery' => 'Gallery',
            '/en/social' => 'Social',
            '/en/contact' => 'Contact',
        ];

        foreach ($cases as $page => $label) {
            $current = $this->xpath($page)->query('//nav//a[@aria-current="page"]');

            $this->assertSame(1, $current->length,
                "{$page} marks ".$current->length.' navigation links as current; expected exactly 1.');

            $this->assertSame($label, trim($current->item(0)->textContent),
                "{$page} marks the wrong navigation link as current.");
        }
    }

    /** Roles that promise a keyboard interface must have one behind them. */
    public function test_the_trips_tabs_are_a_real_tab_widget(): void
    {
        $x = $this->xpath('/en/trips');

        $this->assertSame(1, $x->query('//*[@role="tablist"]')->length, 'No tablist.');
        $this->assertSame(3, $x->query('//*[@role="tab"]')->length, 'Expected three tabs.');
        $this->assertSame(3, $x->query('//*[@role="tabpanel"]')->length, 'Expected three panels.');

        // Exactly one selected, and it is the one in the tab order.
        $this->assertSame(1, $x->query('//*[@role="tab"][@aria-selected="true"]')->length);
        $this->assertSame(1, $x->query('//*[@role="tab"][@tabindex="0"]')->length);

        foreach ($x->query('//*[@role="tab"]') as $tab) {
            $controls = $tab->getAttribute('aria-controls');

            $this->assertNotSame('', $controls, 'A tab controls nothing.');
            $this->assertSame(1, $x->query('//*[@id="'.$controls.'"][@role="tabpanel"]')->length,
                "aria-controls=\"{$controls}\" does not point at a tabpanel.");
        }

        $this->assertStringContainsString('ArrowRight', $this->get('/en/trips')->getContent(),
            'The tabs carry tab roles but no arrow-key handling, promising a keyboard interface that is not there.');
    }

    public function test_each_locale_declares_its_own_language_and_direction(): void
    {
        $this->get('/en')->assertSee('lang="en"', false)->assertSee('dir="ltr"', false);
        $this->get('/dv')->assertSee('lang="dv"', false)->assertSee('dir="rtl"', false);
    }

    public function test_every_page_offers_a_skip_link_to_its_main_content(): void
    {
        foreach (self::PAGES as $page) {
            $x = $this->xpath($page);

            $this->assertSame(1, $x->query('//a[@href="#main-content"]')->length,
                "{$page} has no skip link.");
            $this->assertSame(1, $x->query('//*[@id="main-content"]')->length,
                "{$page} has no #main-content for its skip link to reach.");
        }
    }
}
