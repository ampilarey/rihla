<?php

namespace App\Services\Broadcasts\Channels;

use App\Models\Booking;
use App\Models\BroadcastDelivery;
use App\Models\EmergencyBroadcast;
use App\Services\Broadcasts\BroadcastChannel;
use Illuminate\Support\Facades\Mail;

/**
 * Email, when this host can actually send one.
 *
 * `MAIL_MAILER` is `log` here and nobody has supplied real SMTP settings.
 * That is a perfectly valid Laravel setup and a useless emergency channel,
 * so this refuses rather than writing a reassuring line to a log file and
 * recording a delivery.
 *
 * The same reasoning as the BML payment driver: the shape is here, the
 * behaviour arrives with the credentials, and until then it says exactly
 * what is missing.
 */
final class EmailChannel implements BroadcastChannel
{
    /** Mailers that go nowhere a person will look. */
    private const NOT_REALLY_SENDING = ['log', 'array', 'null'];

    public function key(): string
    {
        return 'email';
    }

    public function isAvailable(): bool
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, self::NOT_REALLY_SENDING, true)) {
            return false;
        }

        // A configured mailer with nothing to send *from* bounces on the
        // first message, which during an emergency is the worst moment to
        // find out.
        return filled(config('mail.from.address'));
    }

    public function whyUnavailable(): string
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, self::NOT_REALLY_SENDING, true)) {
            return 'Email is set to "'.$mailer.'", which writes to a log rather than sending. Real SMTP settings are needed.';
        }

        return 'No from-address is configured, so any email sent would bounce.';
    }

    public function deliver(EmergencyBroadcast $broadcast, Booking $booking): array
    {
        $address = $booking->customer?->email;

        if (blank($address)) {
            return [
                'status' => BroadcastDelivery::FAILED,
                'detail' => 'No email address on the booking.',
            ];
        }

        try {
            Mail::raw(
                $broadcast->headline."\n\n".$broadcast->body,
                fn ($message) => $message->to($address)->subject($broadcast->headline),
            );
        } catch (\Throwable $e) {
            // The message, not the stack: this ends up in front of a member
            // of staff deciding whether to phone somebody instead.
            return [
                'status' => BroadcastDelivery::FAILED,
                'detail' => 'The mail server refused it: '.$e->getMessage(),
            ];
        }

        return ['status' => BroadcastDelivery::DELIVERED, 'detail' => 'Sent to '.$address];
    }
}
