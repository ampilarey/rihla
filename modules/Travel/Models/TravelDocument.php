<?php

namespace Modules\Travel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An issued e-ticket, hotel voucher or visa for a trip — docs/
 * TRAVEL_AGENCY_PLAN.md (A2). The file is ciphertext on the documents disk.
 *
 * @property int $id
 * @property int $travel_request_id
 * @property string $kind
 * @property string $label
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size_bytes
 * @property int|null $visa_application_id
 * @property bool $from_customer
 */
class TravelDocument extends Model
{
    /** @var array<string, string> */
    public const KINDS = ['ticket' => 'E-ticket', 'voucher' => 'Hotel voucher', 'visa' => 'Visa', 'other' => 'Other'];

    protected $fillable = [];

    protected $casts = ['size_bytes' => 'integer', 'from_customer' => 'boolean'];

    /** @return BelongsTo<TravelRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }
}
