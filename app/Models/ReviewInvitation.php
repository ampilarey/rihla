<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link that lets a guest review one stay — §16.11. Only the hash is
 * stored; it expires, and it is spent by the review it brings in.
 */
class ReviewInvitation extends Model
{
    protected $fillable = ['stay_id', 'token_hash', 'issued_by', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
