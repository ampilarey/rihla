<?php

namespace Modules\Transport\Models;

use App\Models\Partner;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What Rihla owes a boat operator for one month — docs/TRANSPORT_PLAN.md
 * (T5). The money for seats sold online came to Rihla; Rihla keeps its
 * commission and pays the rest. Seats the operator sold themselves are
 * listed for the record and change nothing.
 *
 * @property string $reference
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $currency
 * @property int $online_count
 * @property int $online_paid_minor
 * @property int $commission_minor
 * @property int $payable_minor
 * @property int $desk_count
 * @property int $desk_total_minor
 */
class OperatorStatement extends Model
{
    protected $fillable = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_at' => 'datetime',
        'online_count' => 'integer',
        'online_paid_minor' => 'integer',
        'commission_minor' => 'integer',
        'payable_minor' => 'integer',
        'desk_count' => 'integer',
        'desk_total_minor' => 'integer',
    ];

    /** @return BelongsTo<Partner, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function money(int $minor): Money
    {
        return Money::ofMinor($minor, $this->currency);
    }
}
