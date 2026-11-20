<?php

namespace Modules\Stays\Models;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stays\Support\HostRole;

/**
 * One person working for one host, in one role — §16.6. See {@see HostRole}.
 */
class HostMembership extends Model
{
    protected $fillable = ['partner_id', 'user_id', 'role', 'invited_by', 'accepted_at'];

    /** @var array<string, string> */
    protected $casts = ['accepted_at' => 'datetime'];

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allows(string $ability): bool
    {
        return $this->accepted_at !== null && HostRole::allows($this->role, $ability);
    }
}
