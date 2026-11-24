<?php

namespace Modules\Transport\Models;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator's boat — docs/TRANSPORT_PLAN.md. Its seats are what a sailing
 * on it sells by default; a sailing may sell fewer, never more.
 */
class Vessel extends Model
{
    public const SPEEDBOAT = 'speedboat';

    public const LAUNCH = 'launch';

    public const DHONI = 'dhoni';

    public const CARGO = 'cargo';

    /** @var array<string, string> */
    public const KINDS = [
        self::SPEEDBOAT => 'Speedboat',
        self::LAUNCH => 'Launch',
        self::DHONI => 'Dhoni',
        self::CARGO => 'Cargo boat',
    ];

    protected $fillable = ['partner_id', 'name', 'kind', 'seats', 'cargo_kg', 'registration_number', 'is_active'];

    protected $casts = [
        'seats' => 'integer',
        'cargo_kg' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Partner, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
