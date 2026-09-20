<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One party waiting for a seat on a full departure.
 *
 * Ordered by when they joined — first come, first served — with one
 * deliberate exception recorded in App\Services\Booking\Waitlist: an entry
 * is skipped when the seats that came back cannot fit it, and the next one
 * that fits is offered instead. Holding two seats empty for a party of four
 * who may never answer serves nobody.
 */
class WaitlistEntry extends Model
{
    use HasFactory;

    protected $fillable = ['departure_id', 'customer_id', 'seats', 'occupancy', 'notes'];

    protected $casts = [
        'seats' => 'integer',
        'offered_at' => 'datetime',
        'offer_expires_at' => 'datetime',
        'converted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'seats' => 1,
        'status' => self::WAITING,
    ];

    /** In the queue. */
    public const WAITING = 'waiting';

    /** Seats are held for them and somebody needs to tell them. */
    public const OFFERED = 'offered';

    /** They booked. */
    public const CONVERTED = 'converted';

    /** The offer ran out before they answered. Their turn has passed. */
    public const EXPIRED = 'expired';

    /** They asked to come off, or staff removed them. */
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [self::WAITING, self::OFFERED, self::CONVERTED, self::EXPIRED, self::CANCELLED];

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<SeatHold, $this> */
    public function seatHold(): BelongsTo
    {
        return $this->belongsTo(SeatHold::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** An offer is still good while its hold is. */
    public function offerIsLive(): bool
    {
        return $this->status === self::OFFERED
            && $this->seatHold !== null
            && $this->seatHold->isLive();
    }

    /** @param  Builder<$this>  $query */
    public function scopeWaiting($query)
    {
        return $query->where('status', self::WAITING);
    }

    /** @param  Builder<$this>  $query */
    public function scopeOffered($query)
    {
        return $query->where('status', self::OFFERED);
    }

    /** First come, first served. */
    public function scopeOldestFirst($query)
    {
        return $query->orderBy('created_at')->orderBy('id');
    }
}
