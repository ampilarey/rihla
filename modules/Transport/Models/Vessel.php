<?php

namespace Modules\Transport\Models;

use App\Exceptions\DeskRefusal;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Transport\Support\OperatorContext;

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

    protected static function booted(): void
    {
        // Inside an operator's panel everything they make is theirs, and
        // nothing of another operator's can be saved, whatever the form sent.
        static::saving(function (self $model): void {
            $operator = OperatorContext::id();

            if ($operator === null) {
                return;
            }

            if ($model->exists && (int) $model->getOriginal('partner_id') !== $operator) {
                throw new DeskRefusal('That belongs to another operator.');
            }

            $model->partner_id = $operator;
        });
    }

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
