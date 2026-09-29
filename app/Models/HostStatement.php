<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One host's month, in one currency, frozen when issued — §16.9.
 */
class HostStatement extends Model
{
    protected $fillable = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_at' => 'datetime',
    ];

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Transfers recorded against this statement — §16 Phase 16.
     *
     * @return HasMany<Payout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function paidOut(): Money
    {
        return Money::ofMinor((int) $this->payouts()->sum('amount_minor'), $this->currency);
    }

    /** What Rihla still holds for the host on this statement, never below zero. */
    public function stillOwed(): Money
    {
        return Money::ofMinor(max(0, (int) $this->rihla_holds_minor - $this->paidOut()->minor), $this->currency);
    }

    public function money(string $column): Money
    {
        return Money::ofMinor((int) $this->getAttribute($column), $this->currency);
    }

    public function label(): string
    {
        return $this->period_start->format('F Y').' · '.$this->currency;
    }
}
