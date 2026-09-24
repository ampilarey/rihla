<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg a departure flies — §8.3.
 *
 * Times are local wall-clock time at each airport, as printed on the
 * ticket, and are never converted: "departs 02:15" means 02:15 in Malé and
 * "arrives 06:40" means 06:40 in Jeddah.
 *
 * `booking_reference` is the airline's PNR for the group. It stays on the
 * staff side: a reference is enough to change or cancel a booking on most
 * airline sites, and the portals are opened from links that get forwarded.
 */
class DepartureFlight extends Model
{
    use HasFactory;

    public const OUTBOUND = 'outbound';

    public const CONNECTING = 'connecting';

    public const RETURN = 'return';

    public const DIRECTIONS = [self::OUTBOUND, self::CONNECTING, self::RETURN];

    protected $fillable = [
        'departure_id', 'direction', 'airline', 'flight_number',
        'from_airport', 'to_airport', 'departs_at', 'arrives_at',
        'seats', 'booking_reference', 'notes',
    ];

    protected $casts = [
        'departs_at' => 'datetime',
        'arrives_at' => 'datetime',
        'seats' => 'integer',
    ];

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** "SV 3301, MLE → JED". */
    public function label(): string
    {
        return trim($this->flight_number).', '.$this->from_airport.' → '.$this->to_airport;
    }

    public static function directionLabel(string $direction): string
    {
        return match ($direction) {
            self::OUTBOUND => 'Outbound',
            self::CONNECTING => 'Connecting',
            self::RETURN => 'Return',
            default => ucfirst($direction),
        };
    }

    /**
     * The leg's direction in the reader's language, for the portal.
     *
     * Whole literal keys, never `__('messages.'.$direction)`: a built key
     * cannot be checked by TranslationTest and reaches the page raw the day
     * a direction is added.
     */
    public function directionWords(): string
    {
        return match ($this->direction) {
            self::OUTBOUND => __('messages.Going'),
            self::RETURN => __('messages.Coming home'),
            default => __('messages.Connecting flight'),
        };
    }
}
