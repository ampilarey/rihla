<?php

namespace App\Models;

use App\Exceptions\IllegalPaymentTransition;
use App\Services\Payments\Ledger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

/**
 * One sum of money received against a booking — §5.3.
 *
 * Knows nothing about any gateway. The chain is
 * `Booking → Payment → PaymentTransaction → provider driver`, and BML
 * appears in exactly one class further down; everything here is the same
 * whether the money came by card, by bank transfer or in an envelope.
 *
 * **A refund is a negative payment**, not a status, pointing at the row it
 * reverses. The original keeps its date, its reference and its slip — what
 * was actually received — and the paid total stays a plain SUM.
 *
 * **Nothing here writes `bookings.paid_minor`.** That is
 * {@see Ledger}, under a row lock, for the same
 * reason SeatAllocator owns the seat counters [R-2]: two people reconciling
 * at once must not both read the same total and both add to it.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payable_type', 'payable_id', 'refund_of_id', 'method', 'provider', 'currency',
        'amount_minor', 'paid_at', 'payer_name', 'payer_bank', 'payer_reference',
        'notes',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'slip_size_bytes' => 'integer',
        'paid_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    // ── How the money arrived ────────────────────────────────────────────

    public const BANK_TRANSFER = 'bank_transfer';

    public const CASH = 'cash';

    public const CARD = 'card';

    /** @var list<string> */
    public const METHODS = [self::BANK_TRANSFER, self::CASH, self::CARD];

    // ── What became of it ────────────────────────────────────────────────

    /** Recorded, nothing claimed yet. A card payment starts here. */
    public const PENDING = 'pending';

    /** The customer says they have sent it. Nobody has checked. */
    public const AWAITING_REVIEW = 'awaiting_review';

    /** Somebody with `payment.reconcile` has seen the money. */
    public const SUCCEEDED = 'succeeded';

    /** The gateway declined it, or the slip was not what it claimed. */
    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::PENDING, self::AWAITING_REVIEW, self::SUCCEEDED, self::FAILED, self::CANCELLED,
    ];

    /**
     * Where a payment may go next.
     *
     * `succeeded` is terminal. Money that turns out not to have arrived is
     * not un-succeeded — that would erase the record of a decision somebody
     * made — it is reversed by a refund row, which is the same discipline
     * as a refused visa application being kept rather than reopened.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::PENDING => [self::AWAITING_REVIEW, self::SUCCEEDED, self::FAILED, self::CANCELLED],
        self::AWAITING_REVIEW => [self::SUCCEEDED, self::FAILED, self::CANCELLED],
        self::SUCCEEDED => [],
        self::FAILED => [],
        self::CANCELLED => [],
    ];

    protected static function booted(): void
    {
        // Minted from the primary key after the insert, for the reason
        // Booking's docblock spells out: deriving it from a count races, and
        // two people reconciling in the same second both get the same
        // number.
        static::created(function (self $payment): void {
            if ($payment->reference === null) {
                $payment->forceFill(['reference' => $payment->makeReference()])->saveQuietly();
            }
        });
    }

    public function makeReference(): string
    {
        return sprintf(
            '%s-%s-%s',
            'RIH-P',
            ($this->created_at ?? now())->format('Y'),
            str_pad((string) $this->getKey(), 4, '0', STR_PAD_LEFT),
        );
    }

    /**
     * What the money is against — §15.3 (Phase 8.6).
     *
     * A Booking today; a Stay from Phase 9. Everything above this line is
     * the same either way, which is the point: a deposit is a deposit
     * whether the thing being paid for has seats and a departure or a
     * check-in date and a room.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The booking this is against, or null when the money is against
     * something else.
     *
     * Deliberately a method and not a relation: `$payment->booking` would
     * read as though every payment has one, and from Phase 9 that is not
     * true. Eloquent throws on a method that does not return a relation, so
     * the old property access fails loudly rather than resolving to null.
     */
    public function booking(): ?Booking
    {
        // The type is read before the relation is touched, so money against
        // something else costs no query — and cannot fail trying to
        // instantiate a class this side of the app does not know about.
        if ($this->payable_type !== Booking::class) {
            return null;
        }

        $payable = $this->payable;

        return $payable instanceof Booking ? $payable : null;
    }

    /**
     * The booking's key without loading it, or null when this is not a
     * booking's money.
     *
     * {@see Ledger} asks this once per lock and once per recompute, on the
     * path a person waits on while reconciling.
     */
    public function bookingKey(): ?int
    {
        return $this->payable_type === Booking::class ? (int) $this->payable_id : null;
    }

    /** @return BelongsTo<Payment, $this> */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_of_id');
    }

    /** @return HasMany<Payment, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Every event, oldest first. Nothing is removed: a disputed
     * reconciliation a year later is answered from here.
     *
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('created_at')->orderBy('id');
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function isRefund(): bool
    {
        return $this->amount_minor < 0;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED, self::CANCELLED], true);
    }

    public function hasSlip(): bool
    {
        return $this->slip_path !== null;
    }

    /**
     * Move a stage, recording who and why.
     *
     * The record of the move is written whether or not anything else
     * happens: "who decided this money was in" is the question a disputed
     * payment turns on, and a status column alone cannot answer it.
     *
     * @throws IllegalPaymentTransition
     */
    public function transitionTo(string $status, ?string $reason = null, ?User $actor = null): void
    {
        if (! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw IllegalPaymentTransition::from($this, $status);
        }

        $from = $this->status;
        $user = $actor ?? Auth::user();

        $changes = ['status' => $status];

        // Reconciliation is a person's decision and is stamped as one.
        if ($status === self::SUCCEEDED || $status === self::FAILED) {
            $changes['reviewed_by'] = $user?->getKey();
            $changes['reviewed_at'] = now();
        }

        if ($status === self::FAILED) {
            $changes['rejection_reason'] = $reason;
        }

        $this->forceFill($changes)->save();

        $this->transactions()->create([
            'type' => PaymentTransaction::REVIEWED,
            'from_status' => $from,
            'to_status' => $status,
            'user_id' => $user?->getKey(),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** @param  Builder<$this>  $query */
    public function scopeSucceeded($query)
    {
        return $query->where('status', self::SUCCEEDED);
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::PENDING, self::AWAITING_REVIEW]);
    }

    /** @param  Builder<$this>  $query */
    public function scopeAwaitingReview($query)
    {
        return $query->where('status', self::AWAITING_REVIEW);
    }
}
