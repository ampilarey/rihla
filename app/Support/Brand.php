<?php

namespace App\Support;

/**
 * The palette, as literal hex, for the places Tailwind classes cannot reach.
 *
 * Hero banners and homepage "why" sections store their colours in the
 * database, so those values are chosen by an editor at runtime and written
 * into inline styles. When a row has no colour set, something has to supply
 * the default — and until now that was a hex literal typed into each Blade
 * template.
 *
 * Those literals were missed when the palette changed, which is why the live
 * homepage went on rendering a blue call-to-action long after the rest of the
 * site had moved to wine. Naming them once means the next palette change
 * cannot leave a component behind.
 *
 * Keep in step with tailwind.config.js; a test asserts they match.
 */
final class Brand
{
    /** Primary: calls to action, active states. White on this is 7.48:1. */
    public const WINE = '#5F498A';

    /** Accent: rules, icons, premium detail. Takes ink text, never white. */
    public const GOLD = '#EFD34D';

    /** Body text and dark UI. */
    public const INK = '#2E2245';

    /** Secondary text. */
    public const INK_MUTED = '#6B6080';

    /** Warm page background. */
    public const CREAM = '#FFFDF0';

    /** Warm neutral border, matched in lightness to Tailwind's gray-300. */
    public const BORDER = '#D4CDE0';

    public const WHITE = '#ffffff';

    /**
     * The wine ramp, matching `tailwind.config.js`.
     *
     * Here because Filament needs the whole scale, not one colour. Handed only
     * the base hex, its `Color::hex()` treats it as the *middle* of a ramp it
     * generates itself — and the primary sits near the dark end, so shade 600
     * (which is what a filled button uses) came out washed out at 3.0:1
     * against white. The panel looked like a different product and failed the contrast
     * the rest of the site is held to.
     *
     * @var array<int, string>
     */
    public const WINE_SCALE = [
        50 => '#F7F5FA',
        100 => '#EDE8F5',
        200 => '#D9CFEA',
        300 => '#BBACD6',
        400 => '#9481BA',
        500 => '#5F498A',
        600 => '#4C3A70',
        700 => '#3C2E59',
        800 => '#2E2245',
        900 => '#1E162E',
        950 => '#130E1E',
    ];
}
