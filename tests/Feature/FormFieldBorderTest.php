<?php

namespace Tests\Feature;

use App\Support\Contrast;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A form field has to be findable — WCAG 1.4.11, 3:1 for its edge.
 *
 * Found on the Stays search, and then on thirty-six fields across the
 * site, the booking forms and the MFA challenge among them. Two faults,
 * both invisible in the markup:
 *
 * - `border-cream-deep` with **no width**. There is no forms plugin, so
 *   Tailwind's preflight leaves a text or number input at `border-width: 0`
 *   and the colour paints nothing. The guests and price boxes rendered as
 *   blank space inside a white card.
 * - `border border-cream-deep`, which has a width and a colour that
 *   measures 1.07:1 against white — the same result by another route.
 *
 * Asserting the property rather than a list of files, for the reason
 * AGENTS.md gives under "assert the property, not the blacklist".
 */
class FormFieldBorderTest extends TestCase
{
    /** The field edge the views use now — `gray-500` in tailwind.config.js. */
    private const FIELD_EDGE = '#6E6680';

    public function test_the_field_edge_is_visible_on_white_and_on_the_page(): void
    {
        $this->assertGreaterThanOrEqual(3.0, Contrast::ratio(self::FIELD_EDGE, '#FFFFFF'));
        $this->assertGreaterThanOrEqual(3.0, Contrast::ratio(self::FIELD_EDGE, '#FFFDF0'));
    }

    public function test_no_form_field_has_a_border_colour_it_cannot_show(): void
    {
        $faults = [];

        foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            preg_match_all('/<(input|select|textarea)\b(?:[^>"]|"[^"]*")*>/s', $file->getContents(), $tags);

            foreach ($tags[0] as $tag) {
                // A checkbox or radio keeps its native appearance (there is no
                // forms plugin), so the browser draws its edge and a border
                // class on it is inert.
                if (preg_match('/type="(hidden|submit|button|file|range|color|checkbox|radio)"/', $tag) === 1
                    || preg_match('/class="([^"]*)"/', $tag, $class) !== 1) {
                    continue;
                }

                $classes = preg_split('/\s+/', trim($class[1])) ?: [];
                $coloured = preg_grep('/^border-(?!\d|[xytbse]-|[xytbse]$|0$|none$|solid$|dashed$)[a-z]/', $classes);

                if (in_array('border-cream-deep', $classes, true) || in_array('border-cream', $classes, true)) {
                    $faults[] = $file->getRelativePathname().': cream edge (1.07:1) on '.trim(substr($tag, 0, 60));
                } elseif ($coloured !== [] && array_intersect($classes, ['border', 'border-2']) === []) {
                    $faults[] = $file->getRelativePathname().': border colour with no width on '.trim(substr($tag, 0, 60));
                }
            }
        }

        $this->assertSame([], $faults);
    }
}
