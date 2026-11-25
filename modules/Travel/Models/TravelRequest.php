<?php

namespace Modules\Travel\Models;

use App\Models\Concerns\HasNotices;
use App\Models\Contracts\NoticeSubject;
use App\Models\Customer;
use App\Services\Payments\TakesPayments;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A trip somebody asked us to plan — docs/TRAVEL_AGENCY_PLAN.md (A1).
 * Requested, quoted (options with an expiry), accepted (one option, by
 * the customer), paid. Every change goes through TravelRequests.
 *
 * @property int $id
 * @property string $reference
 * @property string $status_key
 * @property string $status
 * @property bool $wants_flight
 * @property bool $wants_hotel
 * @property bool $wants_visa
 * @property string|null $origin
 * @property string $destination
 * @property Carbon $depart_on
 * @property Carbon|null $return_on
 * @property int $adults
 * @property int $children
 * @property string $contact_name
 * @property string $contact_phone
 * @property string|null $contact_email
 * @property string $currency
 * @property Carbon|null $quote_expires_at
 * @property int|null $accepted_option_id
 * @property int|null $total_minor
 * @property int $paid_minor
 * @property string|null $cancelled_reason
 */
class TravelRequest extends Model implements NoticeSubject, TakesPayments
{
    use HasNotices;

    public const REQUESTED = 'requested';

    public const QUOTED = 'quoted';

    public const ACCEPTED = 'accepted';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::REQUESTED => 'Requested',
        self::QUOTED => 'Quoted',
        self::ACCEPTED => 'Accepted',
        self::PAID => 'Paid',
        self::CANCELLED => 'Cancelled',
    ];

    /** Options can still be added and a quote sent. */
    public const QUOTABLE = [self::REQUESTED, self::QUOTED];

    protected $fillable = [];

    protected $casts = [
        'wants_flight' => 'boolean',
        'wants_hotel' => 'boolean',
        'wants_visa' => 'boolean',
        'depart_on' => 'date',
        'return_on' => 'date',
        'adults' => 'integer',
        'children' => 'integer',
        'quoted_at' => 'datetime',
        'quote_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'total_minor' => 'integer',
        'paid_minor' => 'integer',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<TravelQuoteOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(TravelQuoteOption::class)->orderBy('id');
    }

    /** @return BelongsTo<TravelQuoteOption, $this> */
    public function acceptedOption(): BelongsTo
    {
        return $this->belongsTo(TravelQuoteOption::class, 'accepted_option_id');
    }

    /** @return HasMany<TravelRequestEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TravelRequestEvent::class)->orderBy('id');
    }

    /** "Flight, hotel and visa", in words. */
    public function wants(): string
    {
        $parts = array_keys(array_filter(['flight' => $this->wants_flight, 'hotel' => $this->wants_hotel, 'visa' => $this->wants_visa]));

        return match (count($parts)) {
            0 => 'trip',
            1 => $parts[0],
            default => implode(', ', array_slice($parts, 0, -1)).' and '.end($parts),
        };
    }

    public function quoteIsLive(): bool
    {
        return $this->status === self::QUOTED && $this->quote_expires_at !== null && $this->quote_expires_at->isFuture();
    }

    public function total(): ?Money
    {
        return $this->total_minor === null ? null : Money::ofMinor($this->total_minor, $this->currency);
    }

    public function owed(): Money
    {
        return Money::ofMinor(max(0, (int) $this->total_minor - $this->paid_minor), $this->currency);
    }

    public function paymentCurrency(): string
    {
        return $this->currency;
    }

    /** The ledger's re-derived total. Paid in full moves an accepted request to paid. */
    public function storePaidTotal(int $minor): void
    {
        $this->forceFill(['paid_minor' => $minor]);

        if ($this->status === self::ACCEPTED && $this->total_minor !== null && $minor >= $this->total_minor) {
            $this->forceFill(['status' => self::PAID])->save();
            TravelRequestEvent::record($this, self::PAID, null, 'Paid in full.');

            return;
        }

        $this->save();
    }

    public function noticeCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function noticeName(): string
    {
        return $this->contact_name;
    }

    public function noticePhone(): ?string
    {
        return $this->contact_phone;
    }

    public function noticeReference(): string
    {
        return $this->reference;
    }

    public function noticeDate(): ?CarbonInterface
    {
        return $this->depart_on;
    }
}
