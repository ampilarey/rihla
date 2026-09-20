<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on the invoice.
 *
 * Amounts are **signed** integer minor units: a discount is a negative line,
 * and the columns are signed so that it stays one rather than wrapping into
 * an enormous positive number. The lines are the truth; the booking's
 * `total_minor` is a cached sum of them.
 *
 * [R-7]: if an amount was converted, the rate used is stored here and never
 * recomputed. A reconciliation six months from now must produce the same
 * number as the receipt did on the day.
 */
class BookingLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'booking_traveller_id', 'type', 'description',
        'quantity', 'unit_amount_minor', 'amount_minor', 'currency',
        'fx_rate', 'fx_from', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_amount_minor' => 'integer',
        'amount_minor' => 'integer',
        'sort_order' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'MVR',
        'quantity' => 1,
        'sort_order' => 0,
    ];

    /** A seat sold at a price tier. */
    public const SEAT = 'seat';

    /** An optional addition — an upgraded room, an extra night, a ziyarah tour. */
    public const EXTRA = 'extra';

    /** Negative. */
    public const DISCOUNT = 'discount';

    /** Positive, and not part of the package — a card fee, a late change. */
    public const FEE = 'fee';

    /** @var list<string> */
    public const TYPES = [self::SEAT, self::EXTRA, self::DISCOUNT, self::FEE];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<BookingTraveller, $this> */
    public function bookingTraveller(): BelongsTo
    {
        return $this->belongsTo(BookingTraveller::class);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
