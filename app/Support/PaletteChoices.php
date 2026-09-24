<?php

namespace App\Support;

/**
 * The brand palette as choices a person can pick from in an admin form.
 *
 * Shared by every screen that lets somebody colour something, so a new one
 * cannot quietly bring back a free picker. Read from {@see Brand} rather
 * than repeated: a palette with more than one source of truth goes stale in
 * whichever copy nobody is looking at.
 */
final class PaletteChoices
{
    /** @return array<string, string> hex => label */
    public static function colours(): array
    {
        return [
            Brand::WHITE => 'White',
            Brand::CREAM => 'Cream',
            Brand::GOLD => 'Gold (for dark grounds only)',
            Brand::GOLD_ON_LIGHT => 'Dark gold',
            Brand::WINE => 'Violet (primary)',
            Brand::INK => 'Ink',
            Brand::INK_MUTED => 'Muted ink',
        ];
    }

    /**
     * The palette plus whatever a record already holds.
     *
     * A record saved under a free picker may carry a colour the palette
     * does not. Offering only the palette would show a blank select and
     * **silently overwrite the stored value on the next save** — repainting
     * a live page because somebody fixed a typo. The current value is kept
     * and labelled, and changing it is a decision made on purpose.
     *
     * @param  array<string, string>  $palette  the choices to extend
     * @return array<string, string>
     */
    public static function including(?string $current, ?array $palette = null): array
    {
        $choices = $palette ?? self::colours();

        if ($current !== null && $current !== '' && ! self::contains($choices, $current)) {
            $choices = [$current => $current.' — not in the palette'] + $choices;
        }

        return $choices;
    }

    /** @param  array<string, string>  $choices */
    public static function contains(array $choices, string $hex): bool
    {
        foreach (array_keys($choices) as $choice) {
            if (strcasecmp($choice, $hex) === 0) {
                return true;
            }
        }

        return false;
    }
}
