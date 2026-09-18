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
    /** Primary: calls to action, active states. White on this is 8.23:1. */
    public const WINE = '#8E2653';

    /** Accent: rules, icons, premium detail. Takes ink text, never white. */
    public const GOLD = '#D2A03C';

    /** Body text and dark UI. */
    public const INK = '#2E2621';

    /** Secondary text. */
    public const INK_MUTED = '#6B6159';

    /** Warm page background. */
    public const CREAM = '#FBF6EC';

    /** Warm neutral border, matched in lightness to Tailwind's gray-300. */
    public const BORDER = '#DBD3CE';

    public const WHITE = '#ffffff';
}
