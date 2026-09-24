<?php

namespace App\Support;

/**
 * What a hero banner may look like — the one list of it.
 *
 * ## Why this file is scanned by Tailwind
 *
 * A banner's size, weight and corner radius are stored as **Tailwind class
 * names** and interpolated into `class="…"` on the homepage. Tailwind only
 * emits a class it has seen written in a file it scans — and until this
 * existed, the only place `font-extrabold`, `font-light`, `rounded-none`
 * and `rounded-sm` were written anywhere was the old Blade admin form's
 * `<option>` list. Deleting that form, which is the point of moving to
 * Filament, would have stopped those four compiling on the next build: a
 * banner saved as "extra bold" rendering at the default weight, with the
 * markup correct and every test passing. `AGENTS.md` records that shape
 * for `bg-wine`.
 *
 * So `tailwind.config.js` scans this file, the Filament form reads its
 * options from here, and `HeroBannerAdminTest` fails if any class listed
 * below is missing from the built CSS. One list, three readers.
 *
 * ## Why colours are a list and not a picker
 *
 * The Blade form used `<input type="color">`, which accepts anything —
 * including the brand gold as a button background with white text, which
 * measures 1.49:1 and which the owner has ruled out in as many words. A
 * picker cannot know that. These are the palette's own values, and the
 * button pairs are additionally held to {@see Contrast::AA} when saved.
 */
final class HeroBannerStyle
{
    /** The second button's default background — the column default, verbatim. */
    public const TRANSLUCENT_WHITE = '#ffffff33';

    /** @var array<string, string> class => label */
    public const HEADING_SIZES = [
        'text-xl' => 'Small',
        'text-2xl' => 'Medium',
        'text-3xl' => 'Large',
        'text-4xl' => 'Extra large',
    ];

    /** @var array<string, string> */
    public const SUBHEADING_SIZES = [
        'text-sm' => 'Small',
        'text-base' => 'Medium',
        'text-lg' => 'Large',
        'text-xl' => 'Extra large',
    ];

    /** @var array<string, string> */
    public const WEIGHTS = [
        'font-light' => 'Light',
        'font-normal' => 'Normal',
        'font-medium' => 'Medium',
        'font-semibold' => 'Semibold',
        'font-bold' => 'Bold',
        'font-extrabold' => 'Extra bold',
    ];

    /** @var array<string, string> */
    public const BUTTON_SIZES = [
        'text-xs' => 'Extra small',
        'text-sm' => 'Small',
        'text-base' => 'Medium',
        'text-lg' => 'Large',
    ];

    /** @var array<string, string> */
    public const RADII = [
        'rounded-none' => 'Square',
        'rounded-sm' => 'Slight',
        'rounded' => 'Small',
        'rounded-md' => 'Medium',
        'rounded-lg' => 'Large',
        'rounded-xl' => 'Extra large',
        'rounded-full' => 'Pill',
    ];

    /**
     * Every class above, for the test that checks the build.
     *
     * @return list<string>
     */
    public static function classes(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::HEADING_SIZES),
            array_keys(self::SUBHEADING_SIZES),
            array_keys(self::WEIGHTS),
            array_keys(self::BUTTON_SIZES),
            array_keys(self::RADII),
        )));
    }

    /**
     * The palette, plus the second button's own translucent default.
     *
     * The shared list lives in {@see PaletteChoices}; this adds the one
     * value only a hero banner uses — white at 20%, for a button sitting
     * over the photograph. It has no single contrast ratio (it depends on
     * the picture), so {@see Contrast::ratio()} declines to measure it
     * rather than guessing.
     *
     * @return array<string, string> hex => label
     */
    public static function colours(): array
    {
        return PaletteChoices::colours() + [
            self::TRANSLUCENT_WHITE => 'Translucent white (over the photograph)',
        ];
    }

    /** @return array<string, string> */
    public static function coloursIncluding(?string $current): array
    {
        return PaletteChoices::including($current, self::colours());
    }

    public static function isPaletteColour(string $hex): bool
    {
        return PaletteChoices::contains(self::colours(), $hex);
    }
}
