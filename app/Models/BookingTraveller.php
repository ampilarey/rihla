<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on one booking, at the price that person's seat actually cost.
 *
 * `price_tier_id` is lineage, not the price: the tier may be repriced or
 * deleted afterwards, and `amount_minor` is the number that was agreed.
 */
class BookingTraveller extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'traveller_id', 'occupancy', 'pax_type',
        'price_tier_id', 'amount_minor', 'is_lead', 'room_reference',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'is_lead' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'pax_type' => 'adult',
        'amount_minor' => 0,
        'is_lead' => false,
    ];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Traveller, $this> */
    public function traveller(): BelongsTo
    {
        return $this->belongsTo(Traveller::class);
    }

    /** @return BelongsTo<PriceTier, $this> */
    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->booking?->currency ?? 'MVR');
    }
}
