<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The vertical half of the design system.
 *
 * Five public pages had four different rhythms between them — a flat `py-16`
 * on the homepage, a flat `py-8` on trips and the gallery, and only the Umrah
 * guide changing with the viewport — so a phone was given the spacing chosen
 * for a desktop. `.section-y` and `.section-y-tight` name it once. See
 * docs/BRAND.md.
 */
class SectionRhythmTest extends TestCase
{
    /** The pages whose top-level sections carry the rhythm. */
    private const PUBLIC_PAGES = [
        'home.blade.php',
        'trips/index.blade.php',
        'trips/show.blade.php',
        'media/gallery.blade.php',
        'pages/guide.blade.php',
    ];

    private function css(): string
    {
        return (string) File::get(resource_path('css/app.css'));
    }

    public function test_the_rhythm_is_defined_once_each(): void
    {
        $css = $this->css();

        $this->assertSame(1, substr_count($css, '.section-y {'));
        $this->assertSame(1, substr_count($css, '.section-y-tight {'));
    }

    /**
     * No page-level wrapper carries flat vertical padding.
     *
     * That is the defect this rhythm exists to fix, stated exactly: the
     * homepage was `py-16` and the trips and gallery pages `py-8` at every
     * width, so a 375px phone got the spacing chosen for a 1440px desktop.
     * A wrapper either uses `.section-y`/`.section-y-tight` or has its own
     * responsive steps.
     *
     * The guide's sticky toolbar is the reason this checks for *flat*
     * padding rather than for the rhythm classes: `py-3 md:py-4` is the
     * height of a toolbar, not a section rhythm, and it responds to the
     * viewport already. Padding inside a button, badge, card or empty state
     * is spacing within a component and is none of this test's business.
     */
    public function test_no_page_wrapper_uses_flat_vertical_padding(): void
    {
        foreach (self::PUBLIC_PAGES as $page) {
            $markup = (string) File::get(resource_path('views/'.$page));

            preg_match_all(
                '/<section[^>]*class="([^"]*)"|<div class="((?:container|max-w-screen-xl) mx-auto[^"]*)"/',
                $markup,
                $matches,
            );

            foreach (array_filter(array_merge($matches[1], $matches[2])) as $classes) {
                if (! preg_match('/(?<![:\w-])py-(\d+)\b/', $classes, $found)) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/\b(?:sm|md|lg):py-\d+\b/',
                    $classes,
                    "{$page} sets a flat py-{$found[1]} on a page wrapper (\"{$classes}\"), so a phone gets the "
                    .'spacing chosen for a desktop. Use .section-y or .section-y-tight.',
                );
            }
        }
    }

    public function test_every_public_page_uses_the_rhythm(): void
    {
        foreach (self::PUBLIC_PAGES as $page) {
            $this->assertStringContainsString(
                'section-y',
                (string) File::get(resource_path('views/'.$page)),
                "{$page} has no section rhythm.",
            );
        }
    }

    /**
     * `.section` and `.container-fluid` sat in the stylesheet unused and
     * undocumented for long enough that `.section` read as the base of the
     * documented `.section-light` / `.section-dark` / `.section-wine` family,
     * which it never was. Every component class the stylesheet defines is
     * named in docs/BRAND.md, so the next one cannot drift the same way.
     */
    public function test_brand_md_documents_every_component_class(): void
    {
        $components = (string) preg_replace(
            '/^.*?@layer components \{/s',
            '',
            $this->css(),
        );

        preg_match_all('/^    (\.[a-z][\w-]*)[,\s]/m', $components, $matches);

        $defined = array_unique($matches[1]);
        $brand = (string) File::get(base_path('docs/BRAND.md'));

        $this->assertNotEmpty($defined, 'No component classes found; the parser is wrong, not the stylesheet.');

        foreach ($defined as $class) {
            $this->assertStringContainsString(
                '`'.$class.'`',
                $brand,
                "{$class} is defined in app.css but not documented in docs/BRAND.md.",
            );
        }
    }
}
