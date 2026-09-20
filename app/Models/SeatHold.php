<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seats taken off a departure while somebody decides.
 *
 * A hold is **live** while `released_at` is null and `expires_at` is in the
 * future. There is no "expired" status column, on purpose: expiry is a fact
 * about the clock, and a stored flag would be wrong from the moment it
 * lapses until a scheduler got round to correcting it — which on cPanel
 * shared hosting means "possibly never". App\Services\Booking\SeatAllocator
 * reclaims lapsed holds inside the same row lock it takes to issue a new
 * one, so the counters are correct whether or not cron ever runs.
 */
class SeatHold extends Model
{
    use HasFactory;

    protected $fillable = ['departure_id', 'booking_id', 'seats', 'expires_at'];

    protected $casts = [
        'seats' => 'integer',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    /** Why the seats went back. */
    public const EXPIRED = 'expired';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isLive(): bool
    {
        return $this->released_at === null && $this->expires_at->isFuture();
    }

    public function hasLapsed(): bool
    {
        return $this->released_at === null && $this->expires_at->isPast();
    }

    /** Seconds left on the clock, floored at zero — what a countdown reads. */
    public function secondsRemaining(): int
    {
        return $this->released_at !== null
            ? 0
            : (int) max(0, now()->diffInSeconds($this->expires_at, false));
    }

    /** @param  Builder<$this>  $query */
    public function scopeLive($query)
    {
        return $query->whereNull('released_at')->where('expires_at', '>', now());
    }

    /** @param  Builder<$this>  $query */
    public function scopeLapsed($query)
    {
        return $query->whereNull('released_at')->where('expires_at', '<=', now());
    }
}
