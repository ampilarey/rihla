<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A customer card or a luggage tag issued to somebody on a booking.
 *
 * The QR on it holds a random token, never a name or a passport number, so
 * a tag that falls off a suitcase gives nothing away; scanning it on the
 * staff Scan page turns it back into the traveller. Issued once per
 * traveller and kind, printed as often as needed; a lost one is reissued,
 * which revokes the old token.
 *
 * @property int $booking_traveller_id
 * @property string $kind
 * @property string $serial
 * @property string $qr_token
 * @property int|null $issued_by
 * @property Carbon $issued_at
 * @property int $printed_count
 * @property Carbon|null $revoked_at
 */
class Card extends Model
{
    public const CUSTOMER = 'customer';

    public const LUGGAGE = 'luggage';

    /** @var array<string, string> */
    public const KINDS = [self::CUSTOMER => 'Customer card', self::LUGGAGE => 'Luggage tag'];

    protected $fillable = ['booking_traveller_id', 'kind'];

    protected $casts = [
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
        'printed_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Card $card): void {
            $card->issued_by ??= Auth::id();
            $card->issued_at ??= now();
            $card->qr_token = $card->qr_token ?: Str::random(40);
            // The serial carries the row's id, which does not exist until
            // the insert; a placeholder keeps the column NOT NULL and
            // unique until `created` fills it in.
            $card->serial = $card->serial ?: 'PENDING-'.Str::upper(Str::random(10));
        });

        static::created(function (Card $card): void {
            if (str_starts_with($card->serial, 'PENDING-')) {
                $card->forceFill(['serial' => self::serialFor($card->kind, (int) $card->getKey())])->saveQuietly();
            }
        });
    }

    /** "RIH-C-000012" for a card, "RIH-L-000012" for a tag. */
    public static function serialFor(string $kind, int $id): string
    {
        return sprintf('RIH-%s-%06d', $kind === self::LUGGAGE ? 'L' : 'C', $id);
    }

    /** @return BelongsTo<BookingTraveller, $this> */
    public function bookingTraveller(): BelongsTo
    {
        return $this->belongsTo(BookingTraveller::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @param  Builder<Card>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** What the QR holds: the Scan page, with this card's token. */
    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/staff/scan?t='.$this->qr_token;
    }
}
