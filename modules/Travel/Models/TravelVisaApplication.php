<?php

namespace Modules\Travel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One applicant's visa for a trip — docs/TRAVEL_AGENCY_PLAN.md (A3). The
 * checklist is copied from the visa list when it is opened, so changing
 * the list later never changes what a customer was asked for.
 *
 * @property int $id
 * @property int $travel_request_id
 * @property int $visa_product_id
 * @property string $applicant_name
 * @property string $status
 * @property list<array{item: string, received_at: string|null}> $checklist
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 */
class TravelVisaApplication extends Model
{
    protected $table = 'travel_visa_applications';

    public const COLLECTING = 'collecting';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    /** @var array<string, string> */
    public const STATUSES = [
        self::COLLECTING => 'Collecting documents',
        self::SUBMITTED => 'Submitted',
        self::APPROVED => 'Approved',
        self::REFUSED => 'Refused',
    ];

    protected $fillable = [];

    protected $casts = [
        'checklist' => 'array',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    /** @return BelongsTo<TravelRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    /** @return BelongsTo<VisaProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(VisaProduct::class, 'visa_product_id');
    }

    /** @return HasMany<TravelDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(TravelDocument::class, 'visa_application_id')->orderBy('id');
    }

    public function received(): int
    {
        return count(array_filter($this->checklist, fn (array $line): bool => $line['received_at'] !== null));
    }

    public function isComplete(): bool
    {
        return $this->received() === count($this->checklist);
    }
}
