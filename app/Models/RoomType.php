<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * What a night in a property actually buys — §15.4 (Phase 9.1).
 *
 * `quantity` is the number of this room the building physically has, and it
 * is the ceiling the no-double-booking invariant counts against: the number
 * of held and confirmed stays overlapping any one night may never exceed
 * it. Phase 9.2 enforces that inside a transaction with
 * `SELECT ... FOR UPDATE`, the way §5.1 already enforces seats on a
 * departure — and with the concurrency test that only means anything on
 * MySQL.
 *
 * The rate here is the base. Seasonal overrides are a separate table in 9.2
 * rather than more columns, because a guesthouse quotes a different price
 * for December than for May and neither is "the" price.
 */
class RoomType extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'property_id', 'name', 'description',
        'sleeps', 'beds', 'size_m2', 'amenities',
        'quantity', 'base_rate_minor', 'sort_order',
    ];

    /** @var array<int, string> */
    public array $translatable = ['name', 'description', 'amenities'];

    /** @var array<string, string> */
    protected $casts = [
        'amenities' => 'array',
        'sleeps' => 'integer',
        'size_m2' => 'integer',
        'quantity' => 'integer',
        'base_rate_minor' => 'integer',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return HasMany<Rate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(Rate::class)->orderBy('starts_on');
    }

    /** @return HasMany<BlockedDate, $this> */
    public function blockedDates(): HasMany
    {
        return $this->hasMany(BlockedDate::class)->orderBy('date');
    }

    /** @return HasMany<Stay, $this> */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    /** @return list<string> */
    public function getAmenityListAttribute(): array
    {
        return is_array($this->amenities) ? array_values($this->amenities) : [];
    }

    /**
     * The base rate, in the currency of the property that owns it.
     *
     * A room type has no currency column on purpose: a stay is one payment,
     * and two rooms in one building quoted in two currencies is not
     * something a booking form can add up.
     */
    public function baseRate(): Money
    {
        // `property_id` is NOT NULL and constrained, so the building is
        // always there. No `?->` fallback: a room type that cannot reach its
        // property is a broken row, and quietly pricing it in USD would put
        // a dollar sign on a Malé rental quoted in rufiyaa.
        return Money::ofMinor($this->base_rate_minor, $this->property->currency);
    }
}
