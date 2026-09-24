<?php

namespace App\Support;

/**
 * WCAG contrast between two colours — the formula, in one place.
 *
 * It lived as a private method inside `BrandColourTest`, which is where the
 * brand's own pairs are asserted. That was enough while the only colours
 * that mattered were the palette's. It stopped being enough the moment a
 * form let somebody *choose* a colour pair: a check that runs in CI cannot
 * stop an admin painting a button gold-on-white at 1.49:1 on a Tuesday.
 *
 * So the formula is here, the test uses it, and so does anything that
 * accepts a colour from a person.
 */
final class Contrast
{
    /** WCAG AA for body-sized text — what a button label is held to. */
    public const AA = 4.5;

    /**
     * The ratio between two hex colours, or null when either is not a
     * solid hex colour.
     *
     * Null rather than a guess for anything else — `rgba(255,255,255,0.2)`
     * over a photograph has no single contrast ratio, because it depends
     * on whatever is underneath. A caller that needs a verdict must decide
     * what to do with "cannot be measured", which is a different answer
     * from "measured and fine".
     */
    public static function ratio(?string $a, ?string $b): ?float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        if ($la === null || $lb === null) {
            return null;
        }

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Relative luminance of `#rgb` or `#rrggbb`, or null for anything else. */
    public static function luminance(?string $hex): ?float
    {
        $hex = ltrim(trim((string) $hex), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex) === 1) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return null;
        }

        [$r, $g, $b] = array_map(
            static function (string $pair): float {
                $channel = hexdec($pair) / 255;

                return $channel <= 0.03928
                    ? $channel / 12.92
                    : (($channel + 0.055) / 1.055) ** 2.4;
            },
            str_split($hex, 2),
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
