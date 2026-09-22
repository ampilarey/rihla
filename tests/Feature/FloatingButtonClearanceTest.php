<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The copyright line has to pass the floating buttons, not sit under them.
 *
 * The WhatsApp and Call buttons are `position: fixed`, so at the bottom of a
 * page they land on whatever the footer ends with. For three goes at this the
 * answer was vertical: reserve a band below the last line big enough for the
 * stack — `pb-52`, then `pb-40`, then `pb-24`. Each removed some genuinely
 * dead space and left the rest, because the band was the problem. Two buttons
 * at its right edge is the only thing in it, so the other three-quarters reads
 * as the page ending and then continuing into nothing. It was reported three
 * times.
 *
 * It is horizontal instead now. The buttons get a gutter beside the copyright,
 * which costs no height at all, and the footer ends on the ordinary `py-12`.
 *
 * The gutter is sized against the paragraph's BOX, not its glyphs. Centred
 * text stops short of the edge, so a narrower gutter looks fine in English
 * today and collides the moment a translation or a longer company name fills
 * the line.
 */
class FloatingButtonClearanceTest extends TestCase
{
    /** Tailwind's spacing scale: one unit is 0.25rem, and the root is 16px. */
    private const PX_PER_UNIT = 4;

    /** `container mx-auto px-4` — the gutter starts inside this. */
    private const CONTAINER_PADDING_PX = 16;

    /** How much wider than strictly needed the gutter may be. */
    private const SLACK_PX = 48;

    private function fab(): string
    {
        return File::get(resource_path('views/components/whatsapp-fab.blade.php'));
    }

    private function layout(): string
    {
        return File::get(resource_path('views/layouts/app.blade.php'));
    }

    /** The classes that apply on a phone: anything with a `:` is a breakpoint. */
    private function mobileClasses(string $markup): string
    {
        preg_match('/class="fixed ([^"]*)"/', $markup, $classes);

        $this->assertNotEmpty($classes, 'The floating stack has no class list to read.');

        return implode(' ', array_filter(
            preg_split('/\s+/', trim($classes[1])),
            static fn (string $c): bool => ! str_contains($c, ':'),
        ));
    }

    /**
     * How far in from the viewport's right edge the stack reaches.
     *
     * Its own offset plus its width, and width depends on direction: side by
     * side the buttons add up with the gaps between them; stacked they are as
     * wide as the widest one.
     */
    private function stackReachPx(): int
    {
        $fab = $this->fab();

        preg_match('/class="fixed [^"]*?right-(\d+)/', $fab, $offset);
        $this->assertNotEmpty($offset, 'The floating stack has no right-N offset to read.');

        preg_match_all('/\bw-(\d+) h-(\d+) rounded-full/', $fab, $buttons, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(1, count($buttons),
            'No floating buttons found; the gutter beside them cannot be derived.');

        $widths = array_map(static fn ($b) => (int) $b[1] * self::PX_PER_UNIT, $buttons);

        $mobile = $this->mobileClasses($fab);

        if (str_contains($mobile, 'flex-col')) {
            return (int) $offset[1] * self::PX_PER_UNIT + max($widths);
        }

        preg_match('/gap-(\d+)|space-x-(\d+)/', $mobile, $gap);
        $this->assertNotEmpty($gap, 'The side-by-side floating buttons have no gap to read.');

        $gapPx = (int) ($gap[1] !== '' ? $gap[1] : $gap[2]) * self::PX_PER_UNIT;

        return (int) $offset[1] * self::PX_PER_UNIT
            + array_sum($widths)
            + (count($buttons) - 1) * $gapPx;
    }

    private function copyrightGutterPx(): int
    {
        preg_match('/<p dir="ltr" class="[^"]*?\bpe-(\d+)\b/', $this->layout(), $gutter);

        $this->assertNotEmpty($gutter,
            'The copyright line declares no inline-end gutter, so the floating buttons land on it.');

        return (int) $gutter[1] * self::PX_PER_UNIT;
    }

    private function requiredGutterPx(): int
    {
        return $this->stackReachPx() - self::CONTAINER_PADDING_PX;
    }

    public function test_the_copyright_line_clears_the_floating_buttons(): void
    {
        $required = $this->requiredGutterPx();
        $actual = $this->copyrightGutterPx();

        $this->assertGreaterThanOrEqual($required, $actual,
            "The floating buttons reach {$this->stackReachPx()}px in from the viewport's right edge, "
            ."so the copyright line needs a {$required}px gutter inside the container and has {$actual}px. "
            .'Its box would run under them — which English centred text survives by luck and a '
            .'translation does not.');
    }

    public function test_the_gutter_does_not_squeeze_the_line_for_no_reason(): void
    {
        $required = $this->requiredGutterPx();
        $actual = $this->copyrightGutterPx();
        $ceiling = $required + self::SLACK_PX;

        $this->assertLessThanOrEqual($ceiling, $actual,
            "The copyright line gives up {$actual}px to buttons that need {$required}px. "
            .'Every pixel past that is width taken off a line that already wraps on a phone.');
    }

    /** Desktop has the room; the gutter is a phone measure and must reset. */
    public function test_desktop_takes_the_gutter_back(): void
    {
        $this->assertMatchesRegularExpression('/<p dir="ltr" class="[^"]*\bmd:pe-0\b/', $this->layout(),
            'The copyright line keeps its phone gutter at every width, so desktop is indented '
            .'for buttons that sit nowhere near it.');
    }

    /**
     * The band is gone and must not come back.
     *
     * A mobile `pb-` on the footer is the shape this test exists to prevent:
     * it is invisible in every screenshot, passes every other assertion, and
     * is the thing that got reported three times.
     */
    public function test_the_footer_reserves_no_band_below_the_last_line(): void
    {
        preg_match('/<footer class="([^"]*)"/', $this->layout(), $classes);

        $this->assertNotEmpty($classes, 'No footer to read.');

        $mobile = array_filter(
            preg_split('/\s+/', trim($classes[1])),
            static fn (string $c): bool => ! str_contains($c, ':'),
        );

        $band = array_values(array_filter(
            $mobile,
            static fn (string $c): bool => (bool) preg_match('/^pb-\d+$/', $c),
        ));

        $this->assertSame([], $band,
            'The footer declares '.implode(', ', $band).' on a phone. The floating buttons are '
            .'cleared sideways now; a band below the last line is the empty slab this replaced.');
    }
}
