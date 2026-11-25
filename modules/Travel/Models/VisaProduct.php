<?php

namespace Modules\Travel\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One visa on the list staff keep — docs/TRAVEL_AGENCY_PLAN.md (A3,
 * decision 2). Anything not on it is "on request".
 *
 * @property int $id
 * @property string $country
 * @property string $visa_type
 * @property list<string> $documents
 * @property int|null $processing_days
 * @property int|null $fee_minor
 * @property string $currency
 * @property bool $is_active
 */
class VisaProduct extends Model
{
    protected $fillable = ['country', 'visa_type', 'documents', 'processing_days', 'fee_minor', 'currency', 'notes', 'is_active'];

    protected $casts = [
        'documents' => 'array',
        'processing_days' => 'integer',
        'fee_minor' => 'integer',
        'is_active' => 'boolean',
    ];

    public function label(): string
    {
        return $this->country.' — '.$this->visa_type;
    }

    public function fee(): ?Money
    {
        return $this->fee_minor === null ? null : Money::ofMinor($this->fee_minor, $this->currency);
    }
}
