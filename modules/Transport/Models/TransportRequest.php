<?php

namespace Modules\Transport\Models;

use App\Models\Concerns\HasNotices;
use App\Models\Contracts\NoticeSubject;
use App\Models\Customer;
use App\Models\Island;
use App\Models\Partner;
use App\Services\Payments\TakesPayments;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cargo or charter request — docs/TRANSPORT_PLAN.md (T4). Asked for,
 * quoted, paid, then moved along by staff: cargo is received, loaded and
 * delivered; a charter is completed. Every change goes through
 * TransportRequests, which writes the event line.
 *
 * @property int $id
 * @property string $reference
 * @property string $status_key
 * @property string $kind
 * @property string $status
 * @property Carbon $wanted_on
 * @property string $contact_name
 * @property string $contact_phone
 * @property string $currency
 * @property int|null $quote_minor
 * @property int $paid_minor
 * @property Carbon|null $quoted_at
 */
class TransportRequest extends Model implements NoticeSubject, TakesPayments
{
    use HasNotices;

    public const CARGO = 'cargo';

    public const CHARTER = 'charter';

    /** @var array<string, string> */
    public const KINDS = [self::CARGO => 'Cargo', self::CHARTER => 'Charter'];

    public const REQUESTED = 'requested';

    public const QUOTED = 'quoted';

    public const PAID = 'paid';

    public const RECEIVED = 'received';

    public const LOADED = 'loaded';

    public const DELIVERED = 'delivered';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::REQUESTED => 'Requested',
        self::QUOTED => 'Quoted',
        self::PAID => 'Paid',
        self::RECEIVED => 'Received',
        self::LOADED => 'Loaded',
        self::DELIVERED => 'Delivered',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
    ];

    /**
     * Where each kind goes after it is paid, in order. A step is never
     * skipped: a parcel that was "delivered" without being "loaded" is a
     * record nobody can trust.
     *
     * @var array<string, list<string>>
     */
    public const AFTER_PAYMENT = [
        self::CARGO => [self::RECEIVED, self::LOADED, self::DELIVERED],
        self::CHARTER => [self::COMPLETED],
    ];

    /** Still open: it can be quoted again or cancelled. */
    public const OPEN = [self::REQUESTED, self::QUOTED];

    protected $fillable = [];

    protected $casts = [
        'wanted_on' => 'date',
        'passengers' => 'integer',
        'cargo_weight_kg' => 'integer',
        'quote_minor' => 'integer',
        'paid_minor' => 'integer',
        'quoted_at' => 'datetime',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /** @return BelongsTo<Island, $this> */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(Island::class, 'origin_island_id');
    }

    /** @return BelongsTo<Island, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Island::class, 'destination_island_id');
    }

    /** @return HasMany<TransportRequestEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TransportRequestEvent::class)->orderBy('id');
    }

    public function label(): string
    {
        return (self::KINDS[$this->kind] ?? $this->kind).': '.($this->origin->name ?? '?').' → '.($this->destination->name ?? '?');
    }

    /** The next step after this one, or null at the end (or before payment). */
    public function nextStep(): ?string
    {
        $steps = self::AFTER_PAYMENT[$this->kind] ?? [];

        if ($this->status === self::PAID) {
            return $steps[0] ?? null;
        }

        $at = array_search($this->status, $steps, true);

        return $at === false ? null : ($steps[$at + 1] ?? null);
    }

    public function quote(): ?Money
    {
        return $this->quote_minor === null ? null : Money::ofMinor($this->quote_minor, $this->currency);
    }

    public function owed(): Money
    {
        return Money::ofMinor(max(0, (int) $this->quote_minor - $this->paid_minor), $this->currency);
    }

    public function paymentCurrency(): string
    {
        return $this->currency;
    }

    /**
     * The ledger's re-derived total. Paid in full moves a quoted request to
     * paid; its event line is written by the ledger's own transaction.
     */
    public function storePaidTotal(int $minor): void
    {
        $this->forceFill(['paid_minor' => $minor]);

        if ($this->status === self::QUOTED && $this->quote_minor !== null && $minor >= $this->quote_minor) {
            $this->forceFill(['status' => self::PAID]);
            $this->save();
            TransportRequestEvent::record($this, self::PAID, null, 'Paid in full.');

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
        return $this->wanted_on;
    }
}
