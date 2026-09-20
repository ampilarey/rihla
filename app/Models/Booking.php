<?php

namespace App\Models;

use App\Exceptions\IllegalBookingTransition;
use App\Services\Payments\Ledger;
use App\Support\Money;
use App\Support\PackageSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * The aggregate root: one party, one departure, one contract.
 *
 * Everything else in the booking domain hangs off this row —
 * `booking_travellers`, `booking_lines`, `seat_holds` and
 * `booking_status_transitions` — and nothing else may change a booking's
 * status except {@see transitionTo()}, so that every change has a row saying
 * who made it and why.
 *
 * Seats are **not** taken here. Capacity lives on the departure and is only
 * ever moved by App\Services\Booking\SeatAllocator, inside a row lock. A
 * model method that quietly incremented a counter would be exactly the code
 * path the plan's DB-level constraint exists to catch.
 */
class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'departure_id', 'package_snapshot', 'currency',
        'seats', 'total_minor', 'deposit_minor', 'paid_minor', 'notes',
    ];

    protected $casts = [
        'package_snapshot' => 'array',
        'seats' => 'integer',
        'total_minor' => 'integer',
        'deposit_minor' => 'integer',
        'paid_minor' => 'integer',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'MVR',
        'status' => self::DRAFT,
        'seats' => 1,
    ];

    /** Being assembled. No seats held yet. */
    public const DRAFT = 'draft';

    /** Seats are off the departure and the clock is running. */
    public const HELD = 'held';

    /** Paid (or approved by staff). The seats are theirs. */
    public const CONFIRMED = 'confirmed';

    /** The hold lapsed before anyone paid. Final — a returning customer starts a new booking. */
    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /** They travelled and came home. */
    public const COMPLETED = 'completed';

    /** @var list<string> */
    public const STATUSES = [
        self::DRAFT, self::HELD, self::CONFIRMED, self::EXPIRED, self::CANCELLED, self::COMPLETED,
    ];

    /**
     * Where each status may go next.
     *
     * `expired` is final on purpose. Reviving a lapsed booking would mean
     * re-taking seats that somebody else may now hold, at a price that may
     * have changed, under terms that may have changed — all of which is a
     * new booking wearing an old reference. Coming back means starting
     * again, and the old row stays as the record that they tried.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::DRAFT => [self::HELD, self::CANCELLED],
        self::HELD => [self::CONFIRMED, self::EXPIRED, self::CANCELLED],
        self::CONFIRMED => [self::COMPLETED, self::CANCELLED],
        self::EXPIRED => [],
        self::CANCELLED => [],
        self::COMPLETED => [],
    ];

    protected static function booted(): void
    {
        // Freeze the product before the row exists, not after: a booking
        // that is briefly in the database without a snapshot is a booking
        // that can be read without one.
        static::creating(function (self $booking): void {
            $booking->captureSnapshot();
        });

        // The reference is minted from the primary key, which only exists
        // after the insert. Deriving it from a count instead — "how many
        // bookings this year, plus one" — races: two staff saving in the
        // same second both read the same count and both get RIH-B-2026-0418,
        // and the unique index turns one of them into a 500 at the worst
        // possible moment.
        static::created(function (self $booking): void {
            if ($booking->reference === null) {
                $booking->forceFill(['reference' => $booking->makeReference()])->saveQuietly();
            }
        });
    }

    public function makeReference(): string
    {
        return sprintf(
            '%s-%s-%s',
            config('booking.reference.prefix', 'RIH-B'),
            ($this->created_at ?? now())->format('Y'),
            str_pad((string) $this->getKey(), (int) config('booking.reference.pad', 4), '0', STR_PAD_LEFT),
        );
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /**
     * Money recorded against this booking, newest first.
     *
     * Read here; written only through
     * {@see Ledger}, which owns `paid_minor` under
     * a row lock for the same reason SeatAllocator owns the seat counters.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id');
    }

    /** @return HasMany<BookingTraveller, $this> */
    public function travellers(): HasMany
    {
        return $this->hasMany(BookingTraveller::class);
    }

    /** @return HasMany<BookingLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BookingLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<SeatHold, $this> */
    public function seatHolds(): HasMany
    {
        return $this->hasMany(SeatHold::class);
    }

    /** @return HasMany<BookingStatusTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(BookingStatusTransition::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Freeze what is being sold. Called once, when the booking is created.
     *
     * Not called again on save: re-snapshotting an existing booking is the
     * bug this column exists to prevent.
     */
    public function captureSnapshot(): void
    {
        if ($this->package_snapshot === null && $this->departure !== null) {
            $this->package_snapshot = PackageSnapshot::of($this->departure);
        }
    }

    /**
     * Move to a new status, recording who and why.
     *
     * The only way the column changes. `$actor` defaults to whoever is
     * signed in, and stays null when nobody is — a hold expiring at 02:00
     * has no author, and putting a name against it would be a lie in the
     * one record whose job is not to lie.
     */
    public function transitionTo(string $status, ?string $reason = null, ?User $actor = null): void
    {
        if (! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw IllegalBookingTransition::from($this, $status);
        }

        $from = $this->status;

        $this->forceFill(array_filter([
            'status' => $status,
            'confirmed_at' => $status === self::CONFIRMED ? now() : null,
            'cancelled_at' => $status === self::CANCELLED ? now() : null,
            'cancellation_reason' => $status === self::CANCELLED ? $reason : null,
        ], fn ($value): bool => $value !== null))->save();

        $this->transitions()->create([
            'from_status' => $from,
            'to_status' => $status,
            'user_id' => ($actor ?? Auth::user())?->getKey(),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    // ── Money ────────────────────────────────────────────────────────────
    //
    // Integer minor units in the columns, Money objects at the boundary
    // ([R-7]). Nothing here multiplies or divides by 100.

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function paid(): Money
    {
        return Money::ofMinor($this->paid_minor, $this->currency);
    }

    public function balance(): Money
    {
        return Money::ofMinor($this->total_minor - $this->paid_minor, $this->currency);
    }

    /**
     * Recalculate the total from the lines.
     *
     * The lines are the truth; `total_minor` is a cached sum so that a list
     * of five hundred bookings is one query rather than five hundred and
     * one. Signed, because a discount is a negative line.
     */
    public function recalculateTotal(): void
    {
        $this->forceFill(['total_minor' => (int) $this->lines()->sum('amount_minor')])->save();
    }

    // ── Reading ──────────────────────────────────────────────────────────

    /** The person operations calls. */
    public function leadTraveller(): ?BookingTraveller
    {
        return $this->travellers->firstWhere('is_lead', true)
            ?? $this->travellers->first();
    }

    /** Seats held or confirmed — what this booking is costing the departure. */
    public function holdsSeats(): bool
    {
        return in_array($this->status, [self::HELD, self::CONFIRMED], true);
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::DRAFT, self::HELD, self::CONFIRMED]);
    }

    /** @param  Builder<$this>  $query */
    public function scopeForDeparture($query, Departure|int $departure)
    {
        return $query->where('departure_id', $departure instanceof Departure ? $departure->getKey() : $departure);
    }
}
