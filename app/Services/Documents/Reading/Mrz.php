<?php

namespace App\Services\Documents\Reading;

/**
 * The two lines at the bottom of a passport (ICAO TD3), read and checked.
 *
 * Each of the passport number, date of birth and expiry carries its own
 * check digit, and a composite digit covers all three, so a reading that
 * passes is almost certainly right and one that fails is flagged before a
 * person looks. OCR confuses O/0, I/1, S/5, B/8, Z/2; the digit-only
 * fields are repaired with the digit reading before checking.
 */
final class Mrz
{
    /** @return array<string, mixed>|null  the fields plus `valid` and `checks`, or null when no MRZ is there */
    public static function parse(string $text): ?array
    {
        $first = null;

        // OCR usually offers the zone more than once — a dedicated pass over
        // the bottom of the page and the full-page pass — so every pairing of
        // a first line with the second line after it is tried, and the one
        // whose check digits pass wins. Failing that, the first pair is
        // reported with its failures, so a person sees what was read.
        foreach (self::candidatePairs($text) as [$one, $two]) {
            $parsed = self::fields($one, $two);

            if ($parsed['valid']) {
                return $parsed;
            }

            $first ??= $parsed;
        }

        return $first;
    }

    /** @return array<string, mixed> */
    private static function fields(string $first, string $second): array
    {
        $passportNumber = rtrim(substr($second, 0, 9), '<');
        $passportCheck = $second[9];
        $nationality = substr($second, 10, 3);
        $dob = self::digits(substr($second, 13, 6));
        $dobCheck = self::digit($second[19]);
        $sex = $second[20];
        $expiry = self::digits(substr($second, 21, 6));
        $expiryCheck = self::digit($second[27]);
        $personal = substr($second, 28, 14);
        $personalCheck = $second[42];
        $composite = self::digit($second[43]);

        $checks = [
            'passport_number' => self::checkDigit(substr($second, 0, 9)) === self::digit($passportCheck),
            'date_of_birth' => self::checkDigit($dob) === $dobCheck,
            'expires_on' => self::checkDigit($expiry) === $expiryCheck,
            'composite' => self::checkDigit(substr($second, 0, 10).$dob.$dobCheck.$expiry.$expiryCheck.$personal.$personalCheck) === $composite,
        ];

        $names = explode('<<', substr($first, 5), 2);

        return [
            'document_type' => $first[0],
            'issuing_country' => str_replace('<', '', substr($first, 2, 3)),
            'surname' => self::words($names[0]),
            'given_names' => self::words($names[1] ?? ''),
            'passport_number' => str_replace('<', '', $passportNumber),
            'nationality' => str_replace('<', '', $nationality),
            'date_of_birth' => self::date($dob, past: true),
            'gender' => match ($sex) {
                'M' => 'male',
                'F' => 'female',
                default => null,
            },
            'expires_on' => self::date($expiry, past: false),
            // The three fields that matter each carry their own digit; the
            // composite also covers the optional personal number, which OCR
            // often smudges, so it is reported but does not decide validity.
            'valid' => $checks['passport_number'] && $checks['date_of_birth'] && $checks['expires_on'],
            'checks' => $checks,
            'lines' => [$first, $second],
        ];
    }

    /**
     * Every first line paired with the next second line, in reading order.
     *
     * A first line is `P`, a type letter or filler, the issuing country and
     * the names with `<<` between them; OCR collapses a run of fillers, so
     * it is taken short and padded, which loses nothing because it carries
     * no check digits. A second line has a date and its digit at 13 and at
     * 21, and must be whole: its digits are what make the reading trusted.
     *
     * @return list<array{string, string}>
     */
    private static function candidatePairs(string $text): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $clean = strtoupper((string) preg_replace('/[^A-Z0-9<]/i', '', str_replace(' ', '', $line)));
            $length = strlen($clean);

            if ($length >= 18 && $length <= 46 && preg_match('/^P[A-Z<][A-Z<]{3}[A-Z<]*<<[A-Z<]*$/', $clean) === 1) {
                $lines[] = ['first', str_pad(substr($clean, 0, 44), 44, '<')];
            } elseif ($length >= 40 && $length <= 46 && substr_count($clean, '<') >= 4
                && ctype_digit(self::digits(substr($clean, 13, 7))) && ctype_digit(self::digits(substr($clean, 21, 7)))) {
                $lines[] = ['second', str_pad(substr($clean, 0, 44), 44, '<')];
            }
        }

        $pairs = [];

        foreach ($lines as $i => [$kind, $line]) {
            if ($kind === 'first' && ($lines[$i + 1][0] ?? null) === 'second') {
                $pairs[] = [$line, $lines[$i + 1][1]];
            }
        }

        return $pairs;
    }

    /** ICAO 9303: weights 7, 3, 1 over digits, letters as 10–35, filler as 0. */
    public static function checkDigit(string $input): int
    {
        $weights = [7, 3, 1];
        $sum = 0;

        foreach (str_split($input) as $i => $char) {
            $value = match (true) {
                ctype_digit($char) => (int) $char,
                ctype_alpha($char) => ord(strtoupper($char)) - 55,
                default => 0,
            };
            $sum += $value * $weights[$i % 3];
        }

        return $sum % 10;
    }

    /** Letters that OCR mistakes for digits, put back as digits. */
    private static function digits(string $value): string
    {
        return strtr($value, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6', '<' => '0']);
    }

    private static function digit(string $char): int
    {
        return (int) self::digits($char);
    }

    private static function date(string $yymmdd, bool $past): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m) !== 1) {
            return null;
        }

        $yy = (int) $m[1];
        $thisYear = (int) date('y');
        // A birth date is in the past; an expiry is within fifteen years either way.
        $century = $past ? ($yy > $thisYear ? 1900 : 2000) : ($yy > $thisYear + 15 ? 1900 : 2000);
        $year = $century + $yy;

        if (! checkdate((int) $m[2], (int) $m[3], $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[3]);
    }

    private static function words(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace('<', ' ', $value)));
    }
}
