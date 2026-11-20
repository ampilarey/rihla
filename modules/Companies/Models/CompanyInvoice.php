<?php

namespace Modules\Companies\Models;

use App\Models\Partner;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Companies\Services\CompanyBilling;

/**
 * Rihla's invoice to an Umrah company: its registration fee, or one month
 * of its monthly fee. See {@see CompanyBilling}.
 *
 * Nothing is fillable: every write goes through the billing service, which
 * is where the rules are.
 *
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon $issued_on
 * @property Carbon $due_on
 * @property Carbon|null $paid_on
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $overdue_notified_at
 */
class CompanyInvoice extends Model
{
    public const REGISTRATION = 'registration';

    public const MONTHLY = 'monthly';

    public const UNPAID = 'unpaid';

    public const OVERDUE = 'overdue';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_on' => 'date',
        'due_on' => 'date',
        'paid_on' => 'date',
        'cancelled_at' => 'datetime',
        'reminded_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
    ];

    /** @return BelongsTo<Partner, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Still owed: neither paid nor cancelled.
     *
     * @param  Builder<CompanyInvoice>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNull('paid_on')->whereNull('cancelled_at');
    }

    public function state(): string
    {
        return match (true) {
            $this->cancelled_at !== null => self::CANCELLED,
            $this->paid_on !== null => self::PAID,
            $this->due_on->lt(now()->startOfDay()) => self::OVERDUE,
            default => self::UNPAID,
        };
    }

    public function isOutstanding(): bool
    {
        return $this->paid_on === null && $this->cancelled_at === null;
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /** What the invoice is for, in words a company's owner reads. */
    public function description(): string
    {
        return $this->kind === self::REGISTRATION
            ? 'Registration fee'
            : 'Monthly fee, '.$this->period_start?->format('F Y');
    }

    public function stateLabel(): string
    {
        return match ($this->state()) {
            self::PAID => 'Paid '.$this->paid_on?->format('j M Y'),
            self::CANCELLED => 'Cancelled',
            self::OVERDUE => 'Overdue since '.$this->due_on->format('j M Y'),
            default => 'Due '.$this->due_on->format('j M Y'),
        };
    }
}
