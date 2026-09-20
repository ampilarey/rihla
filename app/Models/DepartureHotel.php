<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a departure stays, and how far that is from the Haram.
 *
 * The distance is the point. "300 m, about a four-minute walk" is what a
 * pilgrim is actually choosing between; "5-star hotel" is not, and no
 * operator in this market publishes the first.
 */
class DepartureHotel extends Model
{
    use HasFactory;

    protected $fillable = [
        'departure_id', 'city', 'name', 'rating',
        'distance_metres', 'walk_minutes', 'nights', 'sort_order',
    ];

    protected $casts = [
        'distance_metres' => 'integer',
        'walk_minutes' => 'integer',
        'nights' => 'integer',
        'sort_order' => 'integer',
    ];

    public const CITY_MAKKAH = 'makkah';

    public const CITY_MADINAH = 'madinah';

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** "300 m" under a kilometre, "1.2 km" over it. */
    public function getDistanceLabelAttribute(): ?string
    {
        if ($this->distance_metres === null) {
            return null;
        }

        return $this->distance_metres < 1000
            ? $this->distance_metres.' m'
            : round($this->distance_metres / 1000, 1).' km';
    }
}
