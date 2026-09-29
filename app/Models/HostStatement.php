<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function money(string $column): Money
    {
        return Money::ofMinor((int) $this->getAttribute($column), $this->currency);
    }

    public function label(): string
    {
        return $this->period_start->format('F Y').' · '.$this->currency;
    }
}
