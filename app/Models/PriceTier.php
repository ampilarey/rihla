<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price, for one occupancy and traveller type, on one departure.
 *
 * Occupancy is what actually moves an Umrah price — a quad room costs a
 * fraction of a single — and a single "price_from" column cannot say that.
 *
 * The amount is integer minor units ([R-7], see App\Support\Money).
 */
class PriceTier extends Model
{
    use HasFactory;

    protected $fillable = ['departure_id', 'occupancy', 'pax_type', 'amount_minor', 'currency', 'sort_order'];

    protected $casts = [
        'amount_minor' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * The same defaults the columns carry.
     *
     * Without this, a tier created but not re-read has a null currency —
     * the database default only lands on the row, not on the instance in
     * memory — and formatting it threw. A price object that works only
     * after a refresh is a trap for every caller that does not know to.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'MVR',
        'pax_type' => 'adult',
        'sort_order' => 0,
    ];

    public const OCCUPANCIES = ['single', 'double', 'triple', 'quad', 'quint'];

    public const ADULT = 'adult';

    public const CHILD = 'child';

    public const INFANT = 'infant';

    public const PAX_TYPES = [self::ADULT, self::CHILD, self::INFANT];

    /**
     * Which type a traveller of this age counts as, by the bands in
     * config/booking.php. Null age means adult: the form asks for a date of
     * birth, and somebody who did not give one is not thereby a child.
     */
    public static function paxTypeForAge(?int $age): string
    {
        if ($age === null) {
            return self::ADULT;
        }

        foreach ([self::INFANT, self::CHILD] as $type) {
            $ceiling = config("booking.pax_types.{$type}");

            if (is_int($ceiling) && $age <= $ceiling) {
                return $type;
            }
        }

        return self::ADULT;
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function getFormattedAttribute(): string
    {
        return $this->money()->format();
    }
}
