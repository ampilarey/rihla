<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The one place the business's contact number is read.
 *
 * It was previously written out by hand in thirteen places. Twelve of them
 * hard-coded 9607972434 and ignored the WhatsApp number in Admin → Settings
 * entirely — including the floating button that appears on every page, the
 * sticky contact bar, and the footer. Changing the number in the admin panel
 * moved four links and left the rest pointing at the old one.
 *
 * The thirteenth, the "I need help with the Umrah guide" button, read
 * `config('app.whatsapp', '1234567890')`. No such config key has ever
 * existed, in `config/app.php` or anywhere else, so that button pointed at
 * the literal placeholder 1234567890 — on the live site, in both languages.
 */
final class Contact
{
    /**
     * Used when no number is configured.
     *
     * Same value the twelve hard-coded copies carried, so behaviour on a
     * fresh install is unchanged.
     */
    public const FALLBACK = '9607972434';

    /** Digits only, with country code, as wa.me and tel: both require. */
    public static function whatsappNumber(): string
    {
        $configured = self::digits(Setting::getWhatsAppNumber());

        return $configured !== '' ? $configured : self::FALLBACK;
    }

    /**
     * A WhatsApp link for somebody else's number — a host's, on their own
     * page (§16.8). Built here with Rihla's so every `wa.me` link on the
     * site is written in one place. Null when there are no digits to dial.
     */
    public static function whatsappUrlFor(?string $number): ?string
    {
        $digits = self::digits($number);

        return $digits === '' ? null : 'https://wa.me/'.$digits;
    }

    public static function whatsappUrl(?string $message = null): string
    {
        $url = 'https://wa.me/'.self::whatsappNumber();

        return $message === null || $message === ''
            ? $url
            : $url.'?text='.rawurlencode($message);
    }

    /**
     * The number as a human reads it, for display beside a tel: link.
     *
     * The footer printed "+960 797 2434" as literal text next to a link that
     * dialled a different string, so the two could disagree. Maldivian
     * numbers are a three-digit country code and seven digits; anything else
     * is shown as +digits rather than guessed at.
     */
    public static function displayNumber(): string
    {
        $number = self::whatsappNumber();

        if (strlen($number) === 10 && str_starts_with($number, '960')) {
            return '+960 '.substr($number, 3, 3).' '.substr($number, 6);
        }

        return '+'.$number;
    }

    /**
     * A tel: link for somebody else's number — the person who booked, on
     * the Scan page a tour leader holds at the coach door. Built here for
     * the reason `whatsappUrlFor()` is: every dialling link on the site is
     * written in one place, and the leading + is never forgotten. Null when
     * there are no digits to dial; a Maldivian number with no country code
     * is given one, as the rest of this class assumes.
     */
    public static function telUrlFor(?string $number): ?string
    {
        $digits = self::digits($number);

        if ($digits === '') {
            return null;
        }

        return 'tel:+'.(strlen($digits) === 7 ? '960'.$digits : $digits);
    }

    public static function telUrl(): string
    {
        // The leading + was missing everywhere but one call site. Without it
        // a phone may dial 9607972434 as a local number, which from outside
        // the Maldives reaches nobody.
        return 'tel:+'.self::whatsappNumber();
    }

    /**
     * The admin field is a free-text string capped at 20 characters, so
     * "+960 797-2434" is accepted and stored verbatim. wa.me rejects
     * anything but digits, so strip the rest here rather than trusting
     * whatever is already in the settings row.
     */
    private static function digits(?string $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }
}
