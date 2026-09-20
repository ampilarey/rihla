<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a booking's life: from, to, who and why.
 *
 * Append-only, like audit_logs, and for the same reason — a history that can
 * be edited is not a history. There is no `updated_at`, no `$fillable` list
 * beyond what {@see Booking::transitionTo()} writes, and nothing anywhere
 * updates a row.
 */
class BookingStatusTransition extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Null once the account is deleted, and null for a change the system
     * made rather than a person.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
