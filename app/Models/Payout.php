<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bank transfer Rihla made to a host — §16.9, §16 Phase 16.
 *
 * Recorded by Finance after the money has left Rihla's bank; nothing here
 * sends anything. Never edited or deleted: a mistake is answered at the
 * bank and recorded as what it was.
 */
class Payout extends Model
{
    protected $fillable = [];

    /** @var array<string, string> */
    protected $casts = [
        'amount_minor' => 'integer',
        'paid_on' => 'date',
    ];

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<HostStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(HostStatement::class, 'host_statement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
