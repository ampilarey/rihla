<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The footer has to end above the floating buttons, and not far above them.
 *
 * The WhatsApp and Call buttons are `position: fixed`, so at the bottom of a
 * page they sit over whatever the footer ends with — on a phone, the copyright
 * line. The footer pays for that with bottom padding, and that padding was
 * `pb-52`: 208px, against a button stack that occupies 140px. The remaining
 * 68px was dead space below the last line on every phone, which is what it
 * looked like — the page appearing to end, then continuing into nothing.
 *
 * Both failure directions matter, so both are asserted. Too little padding
 * hides a line behind a button; too much is the blank slab that prompted this.
 *
 * The number is not written down twice. It is derived here from the button
 * component's own classes, so adding a third button, resizing them or moving
 * the stack fails this test with the figure it should have been, rather than
 * quietly covering the copyright.
 */
class FloatingButtonClearanceTest extends TestCase
{
    /** Tailwind's spacing scale: one unit is 0.25rem, and the root is 16px. */
    private const PX_PER_UNIT = 4;

    /** How much air to leave below the last line, above the buttons. */
    private const SLACK_PX = 48;

    private function fab(): string
    {
        return File::get(resource_path('views/components/whatsapp-fab.blade.php'));
    }

    private function layout(): string
    {
        return File::get(resource_path('views/layouts/app.blade.php'));
    }

    /**
     * The height the fixed stack occupies, measured from the viewport bottom.
     *
     * Its own offset, plus each button, plus the gaps between them.
     */
    private function clearanceRequired(): int
    {
        $fab = $this->fab();

        preg_match('/class="fixed [^"]*?bottom-(\d+)/', $fab, $offset);
        $this->assertNotEmpty($offset, 'The floating stack has no bottom-N offset to read.');

        preg_match_all('/\bw-(\d+) h-(\d+) rounded-full/', $fab, $buttons, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(1, count($buttons),
            'No floating buttons found; the clearance below cannot be derived.');

        $heights = array_map(static fn ($b) => (int) $b[2] * self::PX_PER_UNIT, $buttons);

        // Direction decides whether the buttons add up or sit beside each
        // other, and only the phone layout matters here — a `md:` prefix is
        // a desktop rule and must not be read as the mobile one.
        preg_match('/class="fixed ([^"]*)"/', $fab, $classes);
        $this->assertNotEmpty($classes, 'The floating stack has no class list to read.');

        $mobile = implode(' ', array_filter(
            preg_split('/\s+/', trim($classes[1])),
            static fn (string $c): bool => ! str_contains($c, ':'),
        ));

        $stacked = str_contains($mobile, 'flex-col');

        if (! $stacked) {
            // A row is as tall as its tallest button; the gap is horizontal.
            return (int) $offset[1] * self::PX_PER_UNIT + max($heights);
        }

        preg_match('/space-y-(\d+)|gap-(\d+)/', $mobile.' '.$fab, $gap);
        $this->assertNotEmpty($gap, 'The stacked floating buttons have no gap to read.');

        $gapPx = (int) ($gap[1] !== '' ? $gap[1] : $gap[2]) * self::PX_PER_UNIT;

        return (int) $offset[1] * self::PX_PER_UNIT
            + array_sum($heights)
            + (count($buttons) - 1) * $gapPx;
    }

    private function footerMobileBottomPadding(): int
    {
        preg_match('/<footer class="[^"]*?\bpb-(\d+)\b/', $this->layout(), $padding);

        $this->assertNotEmpty($padding, 'The footer declares no mobile bottom padding.');

        return (int) $padding[1] * self::PX_PER_UNIT;
    }

    public function test_the_footer_clears_the_floating_buttons(): void
    {
        $required = $this->clearanceRequired();
        $actual = $this->footerMobileBottomPadding();

        $this->assertGreaterThanOrEqual($required, $actual,
            "The floating buttons occupy the bottom {$required}px of the viewport and the footer "
            ."reserves only {$actual}px, so the last line of the footer sits behind them on a phone.");
    }

    public function test_the_footer_does_not_end_in_a_slab_of_nothing(): void
    {
        $required = $this->clearanceRequired();
        $actual = $this->footerMobileBottomPadding();
        $ceiling = $required + self::SLACK_PX;

        $this->assertLessThanOrEqual($ceiling, $actual,
            "The footer reserves {$actual}px below its last line for buttons that occupy {$required}px. "
            .'The difference is empty dark space at the end of every page on a phone; '
            ."anything above {$ceiling}px reads as the page having ended twice.");
    }

    /**
     * Desktop has no such problem and must not inherit the phone's padding.
     *
     * The stack sits in the corner of a much taller viewport beside a footer
     * laid out in four columns, so the copyright is nowhere near it.
     */
    public function test_the_desktop_footer_keeps_its_ordinary_padding(): void
    {
        preg_match('/<footer class="[^"]*?\bmd:pb-(\d+)\b/', $this->layout(), $padding);

        $this->assertNotEmpty($padding,
            'The footer carries its phone padding at every width; md:pb-N is missing.');

        $this->assertLessThan(
            $this->footerMobileBottomPadding(),
            (int) $padding[1] * self::PX_PER_UNIT,
            'Desktop reserves as much space as the phone does, for buttons that do not crowd it.',
        );
    }
}
