<?php

namespace Modules\Travel\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced option on a travel quote: "Emirates via Dubai, 4-star hotel
 * near the Haram, MVR 18,400 for two". The customer accepts one.
 *
 * @property int $id
 * @property int $travel_request_id
 * @property string $title
 * @property string|null $details
 * @property int $price_minor
 * @property string $currency
 */
class TravelQuoteOption extends Model
{
    protected $fillable = [];

    protected $casts = ['price_minor' => 'integer'];

    /** @return BelongsTo<TravelRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    public function price(): Money
    {
        return Money::ofMinor($this->price_minor, $this->currency);
    }
}
