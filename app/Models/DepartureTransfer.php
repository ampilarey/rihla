<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A coach, car or train a departure takes on the ground — §8.3.
 *
 * `meeting_point` is written for the pilgrim ("Hotel lobby, by the main
 * doors") and is shown in their portal. The provider and the driver's
 * number are for the office and the tour leader.
 */
class DepartureTransfer extends Model
{
    use HasFactory;

    public const COACH = 'coach';

    public const CAR = 'car';

    public const TRAIN = 'train';

    public const OTHER = 'other';

    public const MODES = [self::COACH, self::CAR, self::TRAIN, self::OTHER];

    protected $fillable = [
        'departure_id', 'starts_at', 'mode', 'from_place', 'to_place',
        'meeting_point', 'provider', 'contact_phone', 'notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
    ];

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            self::COACH => 'Coach',
            self::CAR => 'Car',
            self::TRAIN => 'Train',
            default => 'Other',
        };
    }

    /** The mode in the reader's language, for the portal. Whole literal keys. */
    public function modeWords(): string
    {
        return match ($this->mode) {
            self::COACH => __('messages.Coach'),
            self::CAR => __('messages.Car'),
            self::TRAIN => __('messages.Train'),
            default => __('messages.Transfer'),
        };
    }
}
