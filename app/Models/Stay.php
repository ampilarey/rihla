<?php

namespace App\Models;

use App\Exceptions\IllegalStayTransition;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody's nights in a guesthouse — §15.4 (Phase 9.2).
 *
 * The dates are **half-open**: `check_in` is slept, `check_out` is not. A
 * stay from the 3rd to the 5th occupies the 3rd and the 4th, and the next
 * guest may check in on the 5th. Every overlap query in this application
 * depends on that, and reading it the other way double-books every
 * changeover day.
 *
 * Only two statuses take a room off the calendar — {@see HELD} and
 * {@see CONFIRMED} — and {@see OCCUPYING} is the single list every
 * availability question asks about. A third status added later that should
 * hold dates and is not put in that list produces an overbooking with no
 * failing test anywhere, so the list is stated once and read everywhere.
 */
class Stay extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'property_id', 'room_type_id',
        'check_in', 'check_out', 'nights', 'adults', 'children',
        'currency', 'rate_snapshot', 'total_minor', 'deposit_minor', 'paid_minor',
        'status', 'requested_at', 'partner_confirmed_at', 'deposit_due_at', 'expires_at',
        'special_requests', 'source',
    ];

    // ── The status machine ───────────────────────────────────────────────

    /** The customer's ask. Holds no dates — anyone else may still book them. */
    public const REQUESTED = 'requested';

    /** The partner said yes. The dates are off the calendar and the clock is running. */
    public const HELD = 'held';

    /** The deposit is paid. The dates are theirs. */
    public const CONFIRMED = 'confirmed';

    /** They arrived. */
    public const CHECKED_IN = 'checked_in';

    /** They stayed and went home. */
    public const COMPLETED = 'completed';

    /** The partner said no. Final. */
    public const DECLINED = 'declined';

    /** The hold ran out before the deposit arrived. Final. */
    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::REQUESTED, self::HELD, self::CONFIRMED, self::CHECKED_IN,
        self::COMPLETED, self::DECLINED, self::EXPIRED, self::CANCELLED,
    ];

    /**
     * The statuses that take a room off the calendar.
     *
     * This list *is* the no-double-booking rule's definition of "taken". A
     * status that should hold dates and is missing here oversells the room
     * silently — no error, no failing test, just two families at one door.
     * `StayAvailabilityTest` asserts the membership of this list directly
     * rather than only its consequences.
     *
     * `requested` is deliberately absent: §15.2 decision 1 makes a request
     * cost the customer nothing until a real room is theirs, which means it
     * cannot take the room away from anybody else either.
     *
     * @var list<string>
     */
    public const OCCUPYING = [self::HELD, self::CONFIRMED];

    /**
     * Where each status may go next.
     *
     * `expired` and `declined` are final, for the reason a lapsed booking
     * is: reviving one means re-taking dates somebody else may now hold, at
     * a rate that may have changed, under terms that may have changed — a
     * new stay wearing an old reference. The row remains as the record that
     * they asked.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::REQUESTED => [self::HELD, self::DECLINED, self::CANCELLED],
        self::HELD => [self::CONFIRMED, self::EXPIRED, self::CANCELLED],
        self::CONFIRMED => [self::CHECKED_IN, self::CANCELLED],
        self::CHECKED_IN => [self::COMPLETED],
        self::COMPLETED => [],
        self::DECLINED => [],
        self::EXPIRED => [],
        self::CANCELLED => [],
    ];

    /** @var array<string, string> */
    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'nights' => 'integer',
        'adults' => 'integer',
        'children' => 'integer',
        'rate_snapshot' => 'array',
        'total_minor' => 'integer',
        'deposit_minor' => 'integer',
        'paid_minor' => 'integer',
        'requested_at' => 'datetime',
        'partner_confirmed_at' => 'datetime',
        'deposit_due_at' => 'datetime',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::REQUESTED,
        'currency' => 'USD',
    ];

    protected static function booted(): void
    {
        // Minted from the primary key, which only exists after the insert.
        // Deriving it from a count races: two requests in the same second
        // read the same count, both get the same reference, and the unique
        // index turns one into a 500. Booking records the same lesson.
        static::created(function (self $stay): void {
            if ($stay->reference === null) {
                $stay->forceFill(['reference' => $stay->makeReference()])->saveQuietly();
            }
        });
    }

    public function makeReference(): string
    {
        return sprintf(
            '%s-%s-%s',
            config('stays.reference.prefix', 'RIH-S'),
            ($this->created_at ?? now())->format('Y'),
            str_pad((string) $this->getKey(), (int) config('stays.reference.pad', 4), '0', STR_PAD_LEFT),
        );
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    /** @param Builder<$this> $query */
    public function scopeOccupying($query)
    {
        return $query->whereIn('status', self::OCCUPYING);
    }

    /**
     * Stays overlapping the half-open range `[$from, $until)`.
     *
     * Two half-open ranges overlap when each starts before the other ends.
     * The comparison is strict on both sides precisely *because* they are
     * half-open: a stay checking out on the 5th and one checking in on the
     * 5th share no night, and `<=` here would refuse a booking the
     * guesthouse can honour.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOverlapping($query, CarbonInterface $from, CarbonInterface $until)
    {
        return $query
            ->whereDate('check_in', '<', $until->toDateString())
            ->whereDate('check_out', '>', $from->toDateString());
    }

    /** A hold whose clock has run out and whose dates should go back. */
    public function scopeLapsed($query)
    {
        return $query
            ->where('status', self::HELD)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    // ── Money ────────────────────────────────────────────────────────────

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function deposit(): Money
    {
        return Money::ofMinor($this->deposit_minor, $this->currency);
    }

    public function paid(): Money
    {
        return Money::ofMinor($this->paid_minor, $this->currency);
    }

    // ── Moving between statuses ──────────────────────────────────────────

    /** Does this stay have the room, as far as everybody else is concerned? */
    public function isOccupying(): bool
    {
        return in_array($this->status, self::OCCUPYING, true);
    }

    /**
     * The only way the status column changes.
     *
     * @throws IllegalStayTransition
     */
    public function transitionTo(string $status, ?string $reason = null): void
    {
        if (! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw IllegalStayTransition::from($this, $status);
        }

        $this->forceFill(array_filter([
            'status' => $status,
            'confirmed_at' => $status === self::CONFIRMED ? now() : null,
            'cancelled_at' => in_array($status, [self::CANCELLED, self::DECLINED], true) ? now() : null,
            'cancellation_reason' => in_array($status, [self::CANCELLED, self::DECLINED], true) ? $reason : null,
        ], fn ($value): bool => $value !== null))->save();
    }
}
