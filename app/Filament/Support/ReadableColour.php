<?php

namespace App\Filament\Support;

use App\Support\Contrast;
use Closure;

/**
 * Colours chosen in an admin form must be readable — measured on what will
 * actually render.
 *
 * ## The hole this closes
 *
 * The first version of this rule, on the hero banner form, measured only
 * the two values a person had typed, and treated an empty one as "cannot be
 * measured — allow it". But an empty colour is not unknown. It resolves to a
 * default, and the default is a fixed, knowable colour. So a gold background
 * with the words left on their default saved without complaint, and the
 * default is white: the 1.49:1 pair that form existed to refuse, stored as
 * `bg=#EFD34D text=#ffffff`.
 *
 * It had a second way to miss. Laravel does not run a closure rule on an
 * empty field at all, so a rule attached only to the words field never
 * fired when the words were left blank — whatever it would have said.
 *
 * So each side is resolved to its **effective** value — what the person
 * chose, or else what renders when they choose nothing — and the rule is
 * attached to **both** fields of the pair. Choosing either one checks the
 * pair.
 *
 * A value that genuinely cannot be measured — a translucent background,
 * which depends on the photograph behind it — still passes: "cannot be
 * measured" is not "measured and fine", but refusing it would refuse a
 * default the site ships with.
 */
final class ReadableColour
{
    /**
     * @param  string  $textField  the words' colour field
     * @param  string  $backgroundField  the button's colour field
     * @param  string  $textDefault  what the words render as when left empty
     * @param  string  $backgroundDefault  what the button renders as when left empty
     */
    public static function pair(
        string $textField,
        string $backgroundField,
        string $textDefault,
        string $backgroundDefault,
    ): Closure {
        return fn ($get): Closure => function (string $attribute, mixed $value, Closure $fail) use (
            $get, $textField, $backgroundField, $textDefault, $backgroundDefault,
        ): void {
            $text = self::effective($get($textField), $textDefault);
            $background = self::effective($get($backgroundField), $backgroundDefault);

            $ratio = Contrast::ratio($text, $background);

            if ($ratio !== null && $ratio < Contrast::AA) {
                $fail(sprintf(
                    'The button\'s words would be %.2f:1 against it — too faint to read. Buttons need at least %.1f:1. Gold takes ink, never white.',
                    $ratio,
                    Contrast::AA,
                ));
            }
        };
    }

    /**
     * One colour on a ground that does not change — a heading on the page,
     * words on a card whose own colour is fixed.
     *
     * Only the chosen side can move, so an empty field falls back to a
     * default that is already known to be readable and there is nothing to
     * check; Laravel not running the rule on an empty field is harmless
     * here, unlike for a {@see pair()}.
     *
     * @param  string  $ground  the fixed colour behind it
     * @param  string  $what  how the failure names it — "The heading"
     */
    public static function on(string $ground, string $what): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($ground, $what): void {
            $ratio = Contrast::ratio(is_string($value) ? $value : null, $ground);

            if ($ratio !== null && $ratio < Contrast::AA) {
                $fail(sprintf('%s would be %.2f:1 against what is behind it — too faint to read. It needs at least %.1f:1.', $what, $ratio, Contrast::AA));
            }
        };
    }

    /**
     * A foreground that is fixed, on a background a person chooses — a
     * card's grey body text on the card colour picked for it.
     */
    public static function behind(string $foreground, string $what): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($foreground, $what): void {
            $ratio = Contrast::ratio($foreground, is_string($value) ? $value : null);

            if ($ratio !== null && $ratio < Contrast::AA) {
                $fail(sprintf('%s would be %.2f:1 on this colour — too faint to read. It needs at least %.1f:1.', $what, $ratio, Contrast::AA));
            }
        };
    }

    /** What renders: the chosen colour, or the default when none was chosen. */
    private static function effective(mixed $chosen, string $default): string
    {
        return is_string($chosen) && trim($chosen) !== '' ? $chosen : $default;
    }
}
