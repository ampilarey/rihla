<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a stay's bill — §16.5, §16 Phase 12.3.
 *
 * The room line is written from the rate snapshot when the stay is
 * confirmed; extras (a transfer, a snorkelling trip, dinner) are added by
 * the host during the stay. A discount or an adjustment is a negative
 * line rather than an edit to another one, so the bill reads as what
 * happened, in order.
 *
 * Append-only: there is no `updated_at`. A charge added in error is
 * reversed with an adjustment, the way a ledger is.
 */
class StayCharge extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'stay_id', 'kind', 'description', 'quantity', 'unit_minor', 'total_minor', 'currency', 'added_by',
    ];

    public const ROOM = 'room';

    public const EXTRA = 'extra';

    public const GREEN_TAX = 'green_tax';

    public const DISCOUNT = 'discount';

    public const ADJUSTMENT = 'adjustment';

    /** @var list<string> */
    public const KINDS = [self::ROOM, self::EXTRA, self::GREEN_TAX, self::DISCOUNT, self::ADJUSTMENT];

    /** @var array<string, string> */
    protected $casts = [
        'quantity' => 'integer',
        'unit_minor' => 'integer',
        'total_minor' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['quantity' => 1];

    protected static function booted(): void
    {
        // The total is the quantity times the price, always. Worked out
        // here rather than trusted from a form, because a bill whose lines
        // do not add up is the one thing a guest checks.
        static::creating(function (self $charge): void {
            $charge->total_minor = $charge->quantity * $charge->unit_minor;
        });
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
