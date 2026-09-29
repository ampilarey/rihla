<?php

namespace App\Models;

use App\Support\Audience;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * Something extra a guest can add when they book — §16.14, §16 Phase 15.
 *
 * Two prices, as a room has: `price_minor` for a visitor, in the listing's
 * currency, and `local_price_minor` for a Maldivian, in rufiyaa. A blank
 * price means *not offered to that audience* — never free.
 */
class PropertyAddon extends Model
{
    use HasFactory, HasTranslations;

    public const PER_STAY = 'per_stay';

    public const PER_PERSON = 'per_person';

    /** @var array<string, string> */
    public const PRICING = [
        self::PER_STAY => 'Per stay',
        self::PER_PERSON => 'Per person',
    ];

    protected $fillable = ['property_id', 'name', 'description', 'pricing', 'price_minor', 'local_price_minor', 'is_active', 'sort_order'];

    /** @var array<int, string> */
    public array $translatable = ['name', 'description'];

    /** @var array<string, string> */
    protected $casts = [
        'price_minor' => 'integer',
        'local_price_minor' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['pricing' => self::PER_STAY, 'is_active' => true, 'sort_order' => 0];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** One of it, for this audience — or null when it is not offered to them. */
    public function priceFor(string $audience): ?Money
    {
        $minor = $audience === Audience::LOCAL ? $this->local_price_minor : $this->price_minor;

        return $minor === null || $this->property === null
            ? null
            : Money::ofMinor($minor, Audience::currencyAt($this->property, $audience));
    }

    public function isOfferedTo(string $audience): bool
    {
        return $this->is_active && $this->priceFor($audience) !== null;
    }

    /** How many of it a party of this size buys. */
    public function quantityFor(int $guests): int
    {
        return $this->pricing === self::PER_PERSON ? max(1, $guests) : 1;
    }

    public function pricingLabel(): string
    {
        return $this->pricing === self::PER_PERSON ? __('messages.per person') : __('messages.per stay');
    }
}
