<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One request from somebody at Rihla to look inside a company's panel —
 * companies plan, Phase 5c. Granted, for a while, only by the company's
 * Owner; never by Rihla. Not {@see Concerns\BelongsToCompany}: it is read
 * when deciding whether a person may enter the company at all, before any
 * company scope applies.
 *
 * @property int $company_id
 * @property int $requested_by
 * @property string $reason
 * @property int|null $decided_by
 * @property Carbon|null $granted_at
 * @property Carbon|null $declined_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class SupportGrant extends Model
{
    public const PENDING = 'pending';

    public const LIVE = 'live';

    public const ENDED = 'ended';

    public const DECLINED = 'declined';

    protected $fillable = ['reason'];

    protected $casts = [
        'granted_at' => 'datetime',
        'declined_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<Partner, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'company_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<SupportVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(SupportVisit::class);
    }

    public function state(): string
    {
        return match (true) {
            $this->declined_at !== null => self::DECLINED,
            $this->granted_at === null => self::PENDING,
            $this->revoked_at !== null, $this->expires_at === null, $this->expires_at->isPast() => self::ENDED,
            default => self::LIVE,
        };
    }

    /**
     * Grants that let someone in right now.
     *
     * @param  Builder<SupportGrant>  $query
     * @return Builder<SupportGrant>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotNull('granted_at')
            ->whereNull('declined_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}
