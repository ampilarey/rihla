<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to work for a host — §16.6. Only the token's hash is kept.
 */
class HostInvitation extends Model
{
    protected $fillable = ['partner_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at'];

    /** @var array<string, string> */
    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isLive(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** @param  Builder<$this>  $query */
    public function scopeLive($query)
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
