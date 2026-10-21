<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person on one booking, at the price that person's seat actually cost.
 *
 * `price_tier_id` is lineage, not the price: the tier may be repriced or
 * deleted afterwards, and `amount_minor` is the number that was agreed.
 *
 * @property int|null $bus_group_id
 * @property Carbon|null $checked_in_at
 * @property string|null $ticket_number
 * @property string|null $seat_number
 */
class BookingTraveller extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'traveller_id', 'occupancy', 'pax_type',
        'price_tier_id', 'amount_minor', 'is_lead', 'room_reference',
        // The airline's two facts about the person, typed once the tickets
        // are issued and printed on the manifest the airline is handed.
        'ticket_number', 'seat_number',
        // Which coach, and whether they have boarded it. Written by
        // App\Services\Operations\Buses and the Scan page, never by the
        // booking form.
        'bus_group_id', 'checked_in_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'is_lead' => 'boolean',
        'bus_group_id' => 'integer',
        'checked_in_at' => 'datetime',
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

    /** @return BelongsTo<BusGroup, $this> */
    public function busGroup(): BelongsTo
    {
        return $this->belongsTo(BusGroup::class);
    }

    /**
     * The cards and tags issued to this person on this booking.
     *
     * @return HasMany<Card, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * `->booking`, not `?->booking`: `booking_id` is NOT NULL, so the
     * relationship cannot be absent on a row that exists, and static
     * analysis correctly reads the nullsafe operator here as dead code.
     * The booking is the only place the currency lives — a copy on this
     * row would be a second thing to keep in step.
     */
    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->booking->currency);
    }
}
