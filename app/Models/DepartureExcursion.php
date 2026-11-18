<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * An additional trip inside a departure (companies plan, Phase 3e, from
 * Hijaz): the Makkah Ziyarah, included; Taif or Jeddah, priced in riyal and
 * paid in Saudi Arabia. Each family's places on it are an
 * {@see ExcursionBooking}, their own payable.
 *
 * @property int $departure_id
 * @property string $title
 * @property string|null $city
 * @property Carbon|null $scheduled_on
 * @property bool $is_included
 * @property int $price_minor
 * @property string $currency
 * @property int|null $capacity
 * @property string|null $provider
 * @property int|null $cost_minor
 * @property string|null $cost_currency
 * @property string|null $notes
 * @property int $sort_order
 */
class DepartureExcursion extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    public const CURRENCIES = ['SAR', 'MVR', 'USD'];

    protected $fillable = [
        'departure_id', 'title', 'city', 'scheduled_on', 'is_included', 'price_minor', 'currency',
        'capacity', 'provider', 'cost_minor', 'cost_currency', 'notes', 'sort_order',
    ];

    protected $casts = [
        'scheduled_on' => 'date',
        'is_included' => 'boolean',
        'price_minor' => 'integer',
        'cost_minor' => 'integer',
        'capacity' => 'integer',
        'sort_order' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'SAR', 'price_minor' => 0, 'is_included' => false];

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return HasMany<ExcursionBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(ExcursionBooking::class);
    }

    /** @return HasManyThrough<ExcursionParticipant, ExcursionBooking, $this> */
    public function participants(): HasManyThrough
    {
        return $this->hasManyThrough(ExcursionParticipant::class, ExcursionBooking::class);
    }

    public function price(): Money
    {
        return Money::ofMinor($this->is_included ? 0 : $this->price_minor, $this->currency);
    }

    public function priceLabel(): string
    {
        return $this->is_included ? 'Included' : $this->price()->format().' per person';
    }

    /** Places taken on bookings that are not cancelled. */
    public function placesTaken(): int
    {
        return ExcursionParticipant::query()
            ->whereIn('excursion_booking_id', $this->bookings()->where('status', '!=', ExcursionBooking::CANCELLED)->select('id'))
            ->count();
    }

    public function placesLeft(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->placesTaken());
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return ['departure'];
    }
}
