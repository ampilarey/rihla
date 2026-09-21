<?php

namespace App\Support;

/**
 * Time-based one-time passwords — RFC 6238, implemented here.
 *
 * ## Why not a package
 *
 * cPanel shared hosting is updated by hand (ADR 0002): every dependency is
 * another thing somebody has to remember to bump, and a stale one in the
 * authentication path is worse than no package at all. RFC 6238 is a
 * base32 decode, one HMAC and a truncation — about forty lines, and it is
 * pinned here against the specification's own published test vectors, so
 * it can be checked rather than trusted.
 *
 * ## What it is compatible with
 *
 * SHA-1, six digits, a thirty-second step: the defaults every authenticator
 * assumes. Google Authenticator, Authy, 1Password, Aegis and the rest all
 * read the `otpauth://` URI from {@see uri()} unchanged.
 *
 * ## Timing
 *
 * {@see verify()} compares with `hash_equals`, and checks one step either
 * side of now. One step, not three: a wider window is a longer replay
 * opportunity, and a clock more than thirty seconds out is a problem the
 * person should fix rather than one this class should paper over.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Seconds per step. The value every authenticator assumes. */
    public const STEP = 30;

    public const DIGITS = 6;

    /** A fresh shared secret, base32, 160 bits as RFC 4226 recommends. */
    public static function secret(): string
    {
        $bytes = random_bytes(20);
        $out = '';

        foreach (str_split($bytes) as $byte) {
            // Two base32 characters per byte is not the packed encoding,
            // but a secret is opaque: what matters is 160 bits of entropy
            // and an alphabet every authenticator accepts.
            $value = ord($byte);
            $out .= self::ALPHABET[$value >> 3 & 31];
            $out .= self::ALPHABET[$value & 31];
        }

        return $out;
    }

    /** The code for a given moment, six digits, zero-padded. */
    public static function code(string $secret, ?int $at = null): string
    {
        $counter = intdiv($at ?? time(), self::STEP);

        $binary = hash_hmac('sha1', pack('J', $counter), self::decode($secret), true);

        // RFC 4226 §5.4's dynamic truncation: the low nibble of the last
        // byte picks where in the digest the code comes from.
        $offset = ord($binary[19]) & 0x0F;

        $number = ((ord($binary[$offset]) & 0x7F) << 24)
            | ((ord($binary[$offset + 1]) & 0xFF) << 16)
            | ((ord($binary[$offset + 2]) & 0xFF) << 8)
            | (ord($binary[$offset + 3]) & 0xFF);

        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Whether a code is right, allowing one step either side.
     *
     * `hash_equals` because a timing difference on a six-digit code is a
     * six-digit code somebody can guess a digit at a time.
     */
    public static function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }

        $now = $at ?? time();

        foreach ([-self::STEP, 0, self::STEP] as $drift) {
            if (hash_equals(self::code($secret, $now + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `otpauth://` URI an authenticator reads.
     *
     * Nothing here renders it as a QR code: that needs a package, and see
     * the class docblock. Every authenticator accepts the secret typed in
     * by hand, and {@see spaced()} groups it so somebody can do that
     * without losing their place.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::STEP,
        ]);
    }

    /** The secret in groups of four, for somebody typing it into a phone. */
    public static function spaced(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /** Base32 (RFC 4648) to raw bytes. */
    private static function decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');

        $bits = '';

        foreach (str_split($secret) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
