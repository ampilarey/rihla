<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * The rooms in this hotel on this departure (§8.2).
     *
     * Hung off the hotel stay rather than the departure, because a party is
     * in a four-bed room in Makkah and a two-bed room in Madinah, and the
     * rooming is not the same in both.
     *
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('label');
    }

    /**
     * The city, in words, in the reader's language.
     *
     * `city` holds 'makkah' and 'madinah', and two views were printing that
     * raw — including the checkout review page, where a customer read
     * "makkah: Swissotel Al Maqam". Found by rendering the page rather than
     * by any test, because a lowercase enum value is a perfectly valid
     * string.
     *
     * Whole literal keys in a match rather than `__('messages.'.$city)`:
     * a concatenated key cannot be checked by anything, which is what
     * TranslationTest exists to enforce.
     */
    public function cityLabel(): string
    {
        return match ($this->city) {
            self::CITY_MAKKAH => __('messages.Makkah'),
            self::CITY_MADINAH => __('messages.Madinah'),
            default => ucfirst((string) $this->city),
        };
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
