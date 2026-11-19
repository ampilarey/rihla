<?php

namespace App\Support;

/**
 * "Send to customer" (companies plan, Phase 3h, from Hijaz): the same
 * message, ready to send by WhatsApp, Viber or Telegram, so whoever has the
 * family on the phone uses the app the family uses.
 *
 * Only links: nothing is sent from the server, and nothing leaves the
 * office's own phone until a person presses send there.
 */
final class SendToCustomer
{
    /**
     * @return array{whatsapp: string, viber: string, telegram: string}
     */
    public static function links(?string $phone, string $message, ?string $url = null): array
    {
        $text = $url === null ? $message : $message."\n".$url;
        $digits = self::internationalDigits($phone);

        return [
            // To the family's own number when there is one; otherwise
            // WhatsApp asks who to send it to.
            'whatsapp' => 'https://wa.me/'.$digits.'?text='.rawurlencode($text),
            'viber' => 'viber://forward?text='.rawurlencode($text),
            'telegram' => 'https://t.me/share/url?url='.rawurlencode($url ?? '').'&text='.rawurlencode($message),
        ];
    }

    /** "9607771234" for a local seven-digit number; digits as given otherwise; empty when none. */
    private static function internationalDigits(?string $phone): string
    {
        $key = PhoneNumber::key($phone);

        if ($key === null) {
            return '';
        }

        return strlen($key) === 7 ? '960'.$key : $key;
    }
}
