<?php

namespace Modules\Companies\Models;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's place at one Umrah company — companies plan, Phase 2.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $role
 * @property Carbon|null $accepted_at
 */
class CompanyMembership extends Model
{
    protected $fillable = ['company_id', 'user_id', 'role', 'invited_by', 'accepted_at'];

    protected $casts = ['accepted_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'company_id');
    }
}
