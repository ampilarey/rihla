<?php

namespace App\Support;

/**
 * Whether e-mail actually leaves this host.
 *
 * The default mailer is `log` (.env.example), which writes to a file and
 * reports success. Anything that e-mails a customer asks this first, so a
 * site with no SMTP settings neither pretends it sent nor records a send.
 */
final class OutgoingMail
{
    private const NOT_REALLY_SENDING = ['log', 'array', 'null'];

    public static function isReal(): bool
    {
        return self::whyNot() === null;
    }

    public static function whyNot(): ?string
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, self::NOT_REALLY_SENDING, true)) {
            return 'Email is set to "'.$mailer.'", which writes to a log rather than sending. Real SMTP settings are needed.';
        }

        // A mailer with nothing to send *from* bounces on the first message.
        if (blank(config('mail.from.address'))) {
            return 'No from-address is configured, so any email sent would bounce.';
        }

        return null;
    }
}
