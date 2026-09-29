<?php

namespace App\Models;

use App\Services\Stays\StayGatekeeper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link into one stay's own page — §16.7. See {@see StayGatekeeper}.
 */
class StayAccess extends Model
{
    protected $fillable = ['stay_id', 'token_hash', 'expires_at', 'issued_by'];

    /** @var array<string, string> */
    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'uses' => 'integer',
    ];

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** Why it is not usable, for a person rather than for a log. */
    public function whyNot(): ?string
    {
        if ($this->revoked_at !== null) {
            return __('messages.That link has been cancelled. Message us and we will send a new one.');
        }

        if ($this->expires_at->isPast()) {
            return __('messages.That link has expired. Message us and we will send a new one.');
        }

        return null;
    }

    /** @param  Builder<$this>  $query */
    public function scopeLive($query)
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
