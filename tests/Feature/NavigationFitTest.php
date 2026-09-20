<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The header nav has to fit at the width it starts showing.
 *
 * The container clips rather than scrolls, so a link that does not fit is
 * simply gone: no scrollbar, nothing to drag, no hint anything is missing.
 * It happened once to staff, who lost links on anything under 1280px, and
 * the fix was to give them their own breakpoint.
 *
 * It then happened again the day Packages became a seventh visitor link.
 * Measured in Chrome at 768px: "Login" sat past the right edge. The layout
 * already carried a note warning about exactly this — a note is not a guard.
 *
 * A Blade-level test cannot measure pixels, so this asserts the rule that
 * measurement established rather than pretending to re-measure: seven
 * visitor links do not fit at `md` and do fit at `lg`.
 */
class NavigationFitTest extends TestCase
{
    use RefreshDatabase;

    /** The most visitor links that fit from `md` — measured, not derived. */
    private const FITS_AT_MD = 6;

    public function test_a_seventh_visitor_link_forces_a_wider_breakpoint(): void
    {
        $links = $this->visitorNavLinkCount();
        $breakpoint = $this->visitorBreakpoint();

        if ($links <= self::FITS_AT_MD) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertNotSame('md', $breakpoint, sprintf(
            'The visitor nav shows %d links from `md`, and only %d fit there — measured in a browser, '
            .'where the last one sat past the right edge at 768px. The header clips rather than scrolls, '
            .'so that link is simply gone. Raise $navDesktop in layouts/app.blade.php to `lg`, or take '
            .'something out of the nav.',
            $links,
            self::FITS_AT_MD,
        ));
    }

    /** Whatever the desktop nav hides, the mobile menu must still offer. */
    public function test_every_desktop_link_is_in_the_mobile_menu(): void
    {
        $layout = (string) File::get(resource_path('views/layouts/app.blade.php'));

        foreach (['packages.index', 'trips.index', 'guide', 'gallery', 'social', 'contact'] as $route) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($layout, "route('{$route}')"),
                "route('{$route}') appears once in the layout, so it is in the desktop nav or the mobile "
                .'menu but not both — and below the breakpoint it would be unreachable.',
            );
        }
    }

    /** The two audiences keep separate breakpoints, for the reason above. */
    public function test_staff_and_visitors_do_not_share_a_breakpoint(): void
    {
        $layout = (string) File::get(resource_path('views/layouts/app.blade.php'));

        $this->assertMatchesRegularExpression(
            "/\\\$navDesktop = Auth::check\(\) \? 'hidden xl:flex' : 'hidden (?:lg|xl):flex'/",
            $layout,
            'Staff see more links than visitors and need the wider breakpoint of the two.',
        );
    }

    private function visitorNavLinkCount(): int
    {
        $html = $this->get('/en')->assertOk()->getContent();

        // The desktop nav block, which is the one the breakpoint governs.
        preg_match('/hidden (?:md|lg|xl):flex[^"]*"[^>]*>(.*?)<\/div>\s*<!-- Mobile/s', (string) $html, $match);

        return substr_count($match[1] ?? '', '<a ');
    }

    private function visitorBreakpoint(): string
    {
        preg_match(
            "/\\\$navDesktop = Auth::check\(\) \? 'hidden \w+:flex' : 'hidden (\w+):flex'/",
            (string) File::get(resource_path('views/layouts/app.blade.php')),
            $match,
        );

        return $match[1] ?? 'md';
    }
}
