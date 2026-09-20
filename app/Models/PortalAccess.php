<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One issued way into the Pilgrim Portal.
 *
 * The plaintext token exists once, in the response that created it, and is
 * never stored: this row holds only its SHA-256. Staff copy the link and
 * send it; if they lose it, they issue another. That is deliberate — a
 * system that can show you the link again is a system that can be made to
 * show it to somebody else.
 */
class PortalAccess extends Model
{
    use HasFactory;

    protected $fillable = ['booking_id', 'token_hash', 'expires_at', 'issued_by'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'uses' => 'integer',
    ];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** Why it is not usable, for a person rather than for a log. */
    public function whyNot(): ?string
    {
        if ($this->revoked_at !== null) {
            return 'That link has been cancelled. Message us and we will send a new one.';
        }

        if ($this->expires_at->isPast()) {
            return 'That link has expired. Message us and we will send a new one.';
        }

        return null;
    }

    /** @param  Builder<$this>  $query */
    public function scopeLive($query)
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
