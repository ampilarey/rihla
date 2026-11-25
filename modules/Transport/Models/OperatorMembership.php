<?php

namespace Modules\Transport\Models;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person working for one boat operator — docs/TRANSPORT_PLAN.md (T5).
 *
 * @property string $role
 * @property Carbon|null $accepted_at
 */
class OperatorMembership extends Model
{
    public const OWNER = 'owner';

    public const CREW = 'crew';

    /** @var array<string, string> */
    public const ROLES = [self::OWNER => 'Owner', self::CREW => 'Crew'];

    /**
     * What each role may do inside the operator's panel. Answered by name in
     * `AuthServiceProvider`; nothing outside the transport area is ever
     * granted there.
     *
     * @var array<string, list<string>>
     */
    public const ABILITIES = [
        self::OWNER => ['transport.viewAny', 'transport.view', 'transport.manage', 'transport.book', 'transport.board'],
        self::CREW => ['transport.viewAny', 'transport.view', 'transport.board'],
    ];

    protected $fillable = [];

    protected $casts = ['accepted_at' => 'datetime'];

    /** @return BelongsTo<Partner, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
