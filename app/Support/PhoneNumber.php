<?php

namespace App\Support;

/**
 * A Maldivian phone number, reduced to something two records can be compared
 * on.
 *
 * The same person's number appears in a spreadsheet as `7712345`,
 * `+960 771 2345`, `960-7712345` and `00960 7712345`, and a naive string
 * comparison calls those four different people. This is the one place that
 * decides they are the same.
 *
 * **Not a validator.** It does not reject a number it cannot parse — a
 * historical spreadsheet is full of numbers that are wrong, truncated or
 * two numbers in one cell, and a strict import is an import that never
 * finishes. What it does is produce a key that is stable for the same
 * number written different ways, and leaves everything else alone.
 */
final class PhoneNumber
{
    private const COUNTRY_CODE = '960';

    /** Local subscriber numbers in the Maldives are seven digits. */
    private const LOCAL_LENGTH = 7;

    /**
     * A comparable form, or null when there are no digits to compare.
     *
     * The country code is stripped rather than added, because a number
     * stored without one is far more common in these spreadsheets than one
     * stored with it, and because a foreign number keeps its own digits and
     * is compared as it stands.
     */
    public static function key(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';

        if ($digits === '') {
            return null;
        }

        // 00960… — the international prefix as it is typed on a landline.
        if (str_starts_with($digits, '00'.self::COUNTRY_CODE)) {
            $digits = substr($digits, 2 + strlen(self::COUNTRY_CODE));
        } elseif (str_starts_with($digits, self::COUNTRY_CODE)
            && strlen($digits) === strlen(self::COUNTRY_CODE) + self::LOCAL_LENGTH) {
            // 960XXXXXXX, but only at exactly the right length: a number
            // that merely begins with 960 and is longer is somebody else's
            // country, or a typo, and stripping it would invent a match.
            $digits = substr($digits, strlen(self::COUNTRY_CODE));
        }

        return $digits;
    }

    /** Whether two numbers, however written, are the same number. */
    public static function same(?string $a, ?string $b): bool
    {
        $left = self::key($a);

        return $left !== null && $left === self::key($b);
    }
}
