<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record behind a negative booking line: what kind, how much, why,
 * who approved it, and whether it was taken back.
 *
 * The line is what the invoice prints and the total sums. This is the
 * reason an auditor asks for, and it outlives the line: reversing a
 * discount deletes the line and keeps this row, stamped, so a booking
 * that was discounted and then was not still shows that it happened.
 *
 * @property int $booking_id
 * @property int|null $booking_line_id
 * @property string $kind
 * @property string $value
 * @property int $amount_minor
 * @property string $reason
 * @property int|null $approved_by
 */
class Discount extends Model
{
    /** A number of rufiyaa off. */
    public const FIXED = 'fixed';

    /** A share of the total off, worked out and frozen when it is given. */
    public const PERCENT = 'percent';

    /** @var list<string> */
    public const KINDS = [self::FIXED, self::PERCENT];

    protected $fillable = [
        'booking_id', 'booking_line_id', 'kind', 'value', 'amount_minor',
        'reason', 'approved_by', 'approved_at', 'reversed_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'approved_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<BookingLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BookingLine::class, 'booking_line_id');
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->booking->currency);
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
