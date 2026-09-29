<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A percentage off a room — §16 Phase 16.
 *
 * {@see self::bestFor()} is the one place that decides which applies: the
 * largest the stay qualifies for, never two added together.
 */
class StayDiscount extends Model
{
    public const LONG_STAY = 'long_stay';

    public const PROMOTION = 'promotion';

    /** @var array<string, string> */
    public const KINDS = [
        self::LONG_STAY => 'Long stay',
        self::PROMOTION => 'Promotion',
    ];

    /** Never more than half off: a mistyped 90 would give rooms away. */
    public const MAX_PERCENT = 50;

    protected $fillable = ['property_id', 'room_type_id', 'name', 'kind', 'percent', 'min_nights', 'starts_on', 'ends_on', 'audience', 'is_active'];

    /** @var array<string, string> */
    protected $casts = [
        'percent' => 'integer',
        'min_nights' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['audience' => 'both', 'is_active' => true];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** Does a stay of this room, from this check-in, for this many nights, for this guest, qualify? */
    public function appliesTo(RoomType $room, CarbonInterface $checkIn, int $nights, string $audience): bool
    {
        $percent = min(self::MAX_PERCENT, (int) $this->percent);

        return $this->is_active
            && $percent > 0
            && (int) $this->property_id === (int) $room->property_id
            && ($this->room_type_id === null || (int) $this->room_type_id === (int) $room->getKey())
            && ($this->audience === 'both' || $this->audience === $audience)
            && ($this->min_nights === null || $nights >= $this->min_nights)
            && ($this->kind !== self::LONG_STAY || $this->min_nights !== null)
            && ($this->starts_on === null || $checkIn->toDateString() >= $this->starts_on->toDateString())
            && ($this->ends_on === null || $checkIn->toDateString() <= $this->ends_on->toDateString());
    }

    /** The largest discount this stay qualifies for, or none. */
    public static function bestFor(RoomType $room, CarbonInterface $checkIn, int $nights, string $audience): ?self
    {
        return self::query()
            ->where('property_id', $room->property_id)
            ->where('is_active', true)
            ->orderByDesc('percent')
            ->orderBy('id')
            ->get()
            ->first(fn (self $discount): bool => $discount->appliesTo($room, $checkIn, $nights, $audience));
    }
}
