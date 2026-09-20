<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * One day of a departure.
 *
 * The plan calls a day-by-day itinerary "the single most requested thing
 * pilgrims ask about".
 */
class ItineraryItem extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = ['departure_id', 'day_number', 'title', 'description', 'city'];

    /** @var array<int, string> */
    public array $translatable = ['title', 'description'];

    protected $casts = ['day_number' => 'integer'];

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }
}
