<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the rate table Finance keeps: how many units of the base
 * currency one unit of a foreign currency was worth from a given day.
 *
 * Append a line rather than editing the last one when the rate moves, so a
 * payment recorded in March can still show the rate March had. The latest
 * line whose day has come is the current rate.
 *
 * @property string $currency
 * @property string $to_currency
 * @property float $rate
 * @property Carbon $effective_on
 * @property int|null $set_by
 * @property string|null $note
 */
class ExchangeRate extends Model
{
    protected $fillable = ['currency', 'to_currency', 'rate', 'effective_on', 'set_by', 'note'];

    protected $casts = [
        'rate' => 'float',
        'effective_on' => 'date',
    ];

    /** @return BelongsTo<User, $this> */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
