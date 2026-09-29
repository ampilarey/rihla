<?php

namespace App\Support;

use App\Models\Property;

/**
 * Who a stay is sold to — §16.3 decision 6.
 *
 * Maldivian guesthouses run two rate cards: a tourist price, in the
 * currency the property is quoted in (dollars, almost always), and a local
 * price in rufiyaa. Both are sold off one calendar. A room with no price
 * for an audience is simply not for sale to it.
 *
 * The audience decides the price, the currency and whether Green Tax is
 * owed. It is chosen by the guest at booking, from their nationality, and
 * frozen onto the stay with the rest of the quote.
 */
final class Audience
{
    public const TOURIST = 'tourist';

    public const LOCAL = 'local';

    /** @var list<string> */
    public const ALL = [self::TOURIST, self::LOCAL];

    /** ISO 3166 codes read as Maldivian, whatever case they arrive in. */
    private const MALDIVIAN = ['MV', 'MDV'];

    /**
     * The currency this audience pays in at this property.
     *
     * A tourist pays in the property's currency — unchanged from before §16,
     * so every existing room keeps the price it had. A local pays in the
     * configured local currency.
     */
    public static function currencyAt(Property $property, string $audience): string
    {
        return $audience === self::LOCAL
            ? strtoupper((string) config('marketplace.currencies.local', 'MVR'))
            : strtoupper((string) $property->currency);
    }

    /** A Maldivian citizen books as a local; anybody else as a tourist. */
    public static function fromNationality(?string $nationality): string
    {
        return in_array(strtoupper(trim((string) $nationality)), self::MALDIVIAN, true)
            ? self::LOCAL
            : self::TOURIST;
    }

    /** The default before the guest has said: Dhivehi readers are local. */
    public static function fromLocale(?string $locale): string
    {
        return $locale === 'dv' ? self::LOCAL : self::TOURIST;
    }

    public static function isValid(?string $audience): bool
    {
        return in_array($audience, self::ALL, true);
    }

    public static function label(string $audience): string
    {
        return $audience === self::LOCAL ? 'Local' : 'Tourist';
    }
}
