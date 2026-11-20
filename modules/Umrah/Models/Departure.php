<?php

namespace Modules\Umrah\Models;

use App\Exceptions\DeskRefusal;
use App\Models\BusGroup;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use App\Support\Audience;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Content\Models\Person;
use Modules\Content\Models\Trip;
use Modules\Umrah\Exceptions\IllegalDepartureTransition;

/**
 * One dated run of a Package.
 *
 * Carries everything that changes between runs: the dates, the airline, the
 * hotels, the day-by-day itinerary, the prices and the seats.
 *
 * @property Carbon|null $date_start null until the dates are fixed (companies plan, Phase 3b)
 * @property Carbon|null $date_end
 */
class Departure extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'package_id', 'trip_id', 'date_start', 'date_end', 'airline',
        'tour_leader_id', 'scholar_id',
        'capacity_total', 'capacity_held', 'capacity_confirmed',
        // The state is set on creation (a draft, an open copy of a test
        // fixture) and moved afterwards only through transitionTo();
        // `is_published` is derived from it and no longer assignable.
        'status',
        // Recorded in Nusuk, and the gate every permit on this departure
        // waits behind (§5.4b). Mass-assignable because the admin form
        // writes them through update(): left out of this list they are
        // dropped without a word, and the only symptom is permits that can
        // never be requested for a departure whose form says they can.
        'nusuk_accommodation_recorded_at', 'nusuk_transport_recorded_at',
    ];

    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'capacity_total' => 'integer',
        'capacity_held' => 'integer',
        'capacity_confirmed' => 'integer',
        'is_published' => 'boolean',
        'status_changed_at' => 'datetime',
        'nusuk_accommodation_recorded_at' => 'datetime',
        'nusuk_transport_recorded_at' => 'datetime',
    ];

    // ── Lifecycle ────────────────────────────────────────────────────────
    //
    // Moved by hand, by somebody with `departure.lifecycle` (batch C from
    // Hijaz). The state decides what the dates used to: only an open
    // departure is listed and sells seats, a closed one keeps its bookings
    // and takes no more, departed and returned drive the board and the
    // leader's tools, and settled closes its books. The dates say when a
    // state looks overdue; they never move it.

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const DEPARTED = 'departed';

    public const RETURNED = 'returned';

    public const SETTLED = 'settled';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::OPEN, self::CLOSED, self::DEPARTED, self::RETURNED, self::SETTLED, self::CANCELLED];

    /** @var array<string, string> */
    public const LABELS = [
        self::DRAFT => 'Draft',
        self::OPEN => 'Open for booking',
        self::CLOSED => 'Closed to booking',
        self::DEPARTED => 'Departed',
        self::RETURNED => 'Returned',
        self::SETTLED => 'Settled',
        self::CANCELLED => 'Cancelled',
    ];

    /**
     * Where each state may go. Open may be marked departed directly — a
     * departure that left while still on sale is the overdue case the
     * board flags, and the office should not need two taps to say so.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::DRAFT => [self::OPEN, self::CANCELLED],
        self::OPEN => [self::CLOSED, self::DEPARTED, self::CANCELLED],
        self::CLOSED => [self::OPEN, self::DEPARTED, self::CANCELLED],
        self::DEPARTED => [self::RETURNED],
        self::RETURNED => [self::SETTLED],
        self::SETTLED => [],
        self::CANCELLED => [],
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::DRAFT,
    ];

    protected static function booted(): void
    {
        // `is_published` is what the public site and the older code read;
        // it is now a shadow of the state, kept true so nothing that still
        // reads it is lied to, and never set by hand.
        static::saving(function (Departure $departure): void {
            $departure->is_published = $departure->status === self::OPEN;
        });

        // A departure anybody has booked is never deleted, by anyone
        // (companies plan, Phase 3a): it is cancelled, so its money and
        // its history stay. Said in words here; otherwise the database's
        // restrict on `bookings.departure_id` answers with an error page.
        static::deleting(function (Departure $departure): void {
            if ($departure->bookings()->exists()) {
                throw new DeskRefusal('This departure has bookings, so it cannot be deleted. Cancel it instead; its money and history stay.');
            }
        });
    }

    /** Whether the flights decide the dates: there is a flight out or home. */
    public function datesFromFlights(): bool
    {
        return $this->flights()->whereIn('direction', [DepartureFlight::OUTBOUND, DepartureFlight::RETURN])->exists();
    }

    /**
     * Take the dates from the flights (companies plan, Phase 3b, Hijaz
     * parity): the first flight out is the first day, the last flight home
     * the last. A direction with no flight leaves its date as it is.
     */
    public function syncDatesFromFlights(): void
    {
        $flights = $this->flights()->get();
        $out = $flights->where('direction', DepartureFlight::OUTBOUND)->min('departs_at');
        // Home on the day the last flight lands, not the day it leaves.
        $home = $flights->where('direction', DepartureFlight::RETURN)
            ->map(fn (DepartureFlight $flight) => $flight->arrives_at ?? $flight->departs_at)
            ->filter()
            ->max();

        $dates = array_filter([
            'date_start' => $out === null ? null : Carbon::parse((string) $out)->toDateString(),
            'date_end' => $home === null ? null : Carbon::parse((string) $home)->toDateString(),
        ]);

        if ($dates !== []) {
            $this->forceFill($dates)->save();
        }
    }

    /** Whether the dates are fixed yet (companies plan, Phase 3b). */
    public function datesKnown(): bool
    {
        return $this->date_start !== null;
    }

    /**
     * The dates in words, or that they are not fixed yet — for every screen
     * and document that prints them, so none prints a blank or crashes.
     */
    public function datesLabel(string $format = 'j M Y'): string
    {
        if ($this->date_start === null) {
            return 'Dates to be set';
        }

        $start = $this->date_start->format($format);

        return $this->date_end === null ? $start : $start.' – '.$this->date_end->format($format);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst($status);
    }

    public function statusLabel(): string
    {
        return self::label((string) $this->status);
    }

    /** Whether seats may be sold at all in this state: open, and only open. */
    public function acceptsBookings(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isSettled(): bool
    {
        return $this->status === self::SETTLED;
    }

    /** Over and done with, one way or the other: nothing on it is anybody's work. */
    public function isHistory(): bool
    {
        return in_array($this->status, [self::SETTLED, self::CANCELLED], true);
    }

    /** @return list<string> */
    public function nextStates(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canBecome(string $status): bool
    {
        return in_array($status, $this->nextStates(), true);
    }

    /**
     * Move the departure along, with a line in its history saying who and
     * why.
     *
     * @throws IllegalDepartureTransition when the move is not allowed from here, or something stands in its way
     */
    public function transitionTo(string $status, ?string $reason = null, ?User $actor = null): void
    {
        if (! $this->canBecome($status)) {
            throw IllegalDepartureTransition::from($this, $status);
        }

        if ($status === self::OPEN && $this->date_start?->isPast()) {
            throw IllegalDepartureTransition::because('A departure that left on '.$this->date_start->format('j M Y').' cannot be opened for booking.');
        }

        // Registration may open before the dates are fixed; travelling may
        // not (companies plan, Phase 3b).
        if ($status === self::DEPARTED && ! $this->datesKnown()) {
            throw IllegalDepartureTransition::because('Set the dates, or enter the flights, before it leaves.');
        }

        if ($status === self::OPEN && $this->capacity_total < 1) {
            throw IllegalDepartureTransition::because('Set the seats before opening it for booking: with none, nothing can be sold.');
        }

        if ($status === self::SETTLED) {
            $estimates = DepartureCost::query()->where('departure_id', $this->getKey())->where('status', DepartureCost::ESTIMATED)->count();

            if ($estimates > 0) {
                throw IllegalDepartureTransition::because(sprintf(
                    'Settling closes the books, and %d %s still %s. Mark each agreed or paid, or remove it, first.',
                    $estimates,
                    $estimates === 1 ? 'cost is' : 'costs are',
                    $estimates === 1 ? 'an estimate' : 'estimates',
                ));
            }
        }

        if ($status === self::CANCELLED) {
            $live = $this->bookings()->whereIn('status', [Booking::HELD, Booking::CONFIRMED])->count();

            if ($live > 0) {
                throw IllegalDepartureTransition::because(sprintf(
                    'Cancel or move the %d live %s first. A held or confirmed seat is money and a place on an aircraft.',
                    $live,
                    $live === 1 ? 'booking' : 'bookings',
                ));
            }
        }

        $from = (string) $this->status;

        $this->forceFill(['status' => $status, 'status_changed_at' => now()])->save();

        $this->events()->create([
            'from_status' => $from,
            'to_status' => $status,
            'user_id' => ($actor ?? Auth::user())?->getKey(),
            'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 255),
            'created_at' => now(),
        ]);
    }

    /** @return HasMany<DepartureCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(DepartureCost::class);
    }

    /** @return HasMany<DepartureEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DepartureEvent::class)->orderBy('id');
    }

    /** The latest move, for "closed by Aminath on 3 Nov: flights full". */
    public function latestEvent(): ?DepartureEvent
    {
        return $this->events()->latest('id')->first();
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * The `trips` row this was backfilled from, or null if created directly.
     * Kept so the migration can be audited and `trips` retired deliberately
     * rather than hopefully.
     *
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * Who travels with the party. Two separate roles, and a departure may
     * have one, both or neither — publishing a leader nobody has named is
     * worse than publishing none.
     *
     * @return BelongsTo<Person, $this>
     */
    public function tourLeader(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'tour_leader_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function scholar(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'scholar_id');
    }

    /** @return HasMany<PriceTier, $this> */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('sort_order')->orderBy('amount_minor');
    }

    /** @return HasMany<DepartureHotel, $this> */
    public function hotels(): HasMany
    {
        return $this->hasMany(DepartureHotel::class)->orderBy('sort_order');
    }

    /**
     * The coaches it rides, in the office's order (Hijaz's bus groups).
     *
     * @return HasMany<BusGroup, $this>
     */
    public function busGroups(): HasMany
    {
        return $this->hasMany(BusGroup::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Its additional trips, in the office's order (companies plan, Phase 3e).
     *
     * @return HasMany<DepartureExcursion, $this>
     */
    public function excursions(): HasMany
    {
        return $this->hasMany(DepartureExcursion::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The legs it flies, in the order they leave (§8.3).
     *
     * @return HasMany<DepartureFlight, $this>
     */
    public function flights(): HasMany
    {
        return $this->hasMany(DepartureFlight::class)->orderBy('departs_at');
    }

    /**
     * Coaches, cars and trains on the ground, in time order (§8.3).
     *
     * @return HasMany<DepartureTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(DepartureTransfer::class)->orderBy('starts_at');
    }

    /**
     * What has to be done before it leaves (§8.3), in the office's order.
     *
     * @return HasMany<DepartureChecklistItem, $this>
     */
    public function checklist(): HasMany
    {
        return $this->hasMany(DepartureChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ItineraryItem, $this> */
    public function itinerary(): HasMany
    {
        return $this->hasMany(ItineraryItem::class)->orderBy('day_number');
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Seats taken off this departure while somebody decides.
     *
     * Read through Modules\Umrah\Services\Booking\SeatAllocator, never written
     * directly: the counters on this row and the rows in this relationship
     * have to move together, under a lock, or the departure oversells.
     *
     * @return HasMany<SeatHold, $this>
     */
    public function seatHolds(): HasMany
    {
        return $this->hasMany(SeatHold::class);
    }

    // ── Seats ────────────────────────────────────────────────────────────
    //
    // A held seat is neither free nor sold, so it is counted separately. In
    // Phase 2 nothing moves `capacity_held`; Phase 3's seat holds do. The
    // arithmetic is written now so the bar does not have to be rewritten
    // when they arrive.

    public function getSeatsTakenAttribute(): int
    {
        return $this->capacity_held + $this->capacity_confirmed;
    }

    public function getSeatsRemainingAttribute(): int
    {
        // Never negative on a page. An oversold departure is a real problem,
        // but a "-3 seats left" bar in front of a customer is a worse one.
        return max(0, $this->capacity_total - $this->seats_taken);
    }

    public function getPercentSoldAttribute(): int
    {
        if ($this->capacity_total < 1) {
            return 0;
        }

        return (int) min(100, round($this->seats_taken / $this->capacity_total * 100));
    }

    /** Whether a seats-remaining bar should be drawn at all. */
    public function getHasCapacityAttribute(): bool
    {
        return $this->capacity_total > 0;
    }

    public function getIsSoldOutAttribute(): bool
    {
        return $this->has_capacity && $this->seats_remaining < 1;
    }

    /**
     * Whether seats can be sold at all.
     *
     * A departure with no capacity recorded is not unlimited — it is
     * unconfigured, and the allocator refuses it. Stated here so a screen
     * can say "booking not open yet" rather than offering a button that
     * throws.
     */
    public function getIsBookableAttribute(): bool
    {
        return $this->acceptsBookings()
            && $this->has_capacity
            && ! $this->is_sold_out
            // Dates not fixed yet: still bookable at the office (Phase 3b);
            // the public site never lists such a departure.
            && ($this->date_start === null || $this->date_start->isFuture());
    }

    // ── Price ────────────────────────────────────────────────────────────

    /**
     * The tier for one occupancy and traveller type, if this departure
     * publishes one.
     *
     * Null rather than a guess: a departure that prices only adults must
     * price everybody as an adult, not have a child discount invented for
     * it. Callers fall back deliberately.
     */
    public function tierFor(string $occupancy, string $paxType = PriceTier::ADULT, string $audience = Audience::LOCAL): ?PriceTier
    {
        return $this->priceTiers
            ->firstWhere(fn (PriceTier $tier): bool => $tier->occupancy === $occupancy
                && $tier->pax_type === $paxType
                && $tier->audience === $audience);
    }

    /**
     * The occupancies this departure actually prices, in price-list order —
     * for one price list, or for any when none is named.
     */
    public function occupanciesOffered(?string $audience = null): array
    {
        $tiers = $audience === null ? $this->priceTiers : $this->priceTiers->where('audience', $audience);

        return array_values(array_filter(
            PriceTier::OCCUPANCIES,
            fn (string $occupancy): bool => $tiers->contains('occupancy', $occupancy),
        ));
    }

    /**
     * The cheapest tier — what a "from" price means.
     *
     * From the local price list, as it always was; a departure priced only
     * for visitors (§16 Phase 15) falls back to theirs rather than showing
     * no price at all. Never the cheapest across two currencies.
     */
    public function getLeadPriceAttribute(): ?Money
    {
        return $this->leadPriceFor(Audience::LOCAL) ?? $this->leadPriceFor(Audience::TOURIST);
    }

    /** The cheapest tier on one price list, or null when it prices nothing there. */
    public function leadPriceFor(string $audience): ?Money
    {
        return $this->priceTiers->where('audience', $audience)->sortBy('amount_minor')->first()?->money();
    }

    // ── Dates ────────────────────────────────────────────────────────────

    /**
     * Both date columns are NOT NULL, so these need no null guard — the
     * first draft had one and static analysis correctly called it dead. A
     * departure without dates is not a state this table can hold; if that
     * ever changes, the migration changes first and these follow.
     */
    public function getNightsAttribute(): int
    {
        // No dates yet (companies plan, Phase 3b): no nights to count.
        if ($this->date_start === null || $this->date_end === null) {
            return 0;
        }

        return (int) $this->date_start->diffInDays($this->date_end);
    }

    /**
     * Whole days until departure; negative once it has left. A departure
     * with no dates yet is as far off as can be: nothing that counts down
     * to it should fire.
     */
    public function getDaysUntilAttribute(): int
    {
        if ($this->date_start === null) {
            return self::DAYS_UNTIL_UNKNOWN;
        }

        return (int) now()->startOfDay()->diffInDays($this->date_start->startOfDay(), false);
    }

    /** {@see getDaysUntilAttribute()} for a departure whose dates are not fixed. */
    public const DAYS_UNTIL_UNKNOWN = 99999;

    /** The first day, formatted, or that it is not fixed yet (Phase 3b). */
    public function startLabel(string $format = 'j M Y', bool $translated = false): string
    {
        if ($this->date_start === null) {
            return 'dates to be set';
        }

        return $translated ? $this->date_start->translatedFormat($format) : $this->date_start->format($format);
    }

    /** The last day, formatted, or that it is not fixed yet (Phase 3b). */
    public function endLabel(string $format = 'j M Y', bool $translated = false): string
    {
        if ($this->date_end === null) {
            return 'dates to be set';
        }

        return $translated ? $this->date_end->translatedFormat($format) : $this->date_end->format($format);
    }

    /**
     * On sale: the one state the public site lists and sells. Named for
     * what it is rather than `published`, so a closed departure — which
     * once was "published" and is still real — is never mistaken for one.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::OPEN);
    }

    /**
     * Still somebody's work: everything but settled and cancelled.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNotHistory($query)
    {
        return $query->whereNotIn('status', [self::SETTLED, self::CANCELLED]);
    }

    /**
     * Dated and still to fly. The public site's view: a departure with no
     * dates is never offered there.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUpcoming($query)
    {
        return $query->where('date_start', '>=', now()->startOfDay());
    }

    /**
     * Still to fly, or not yet dated — the office's view. A departure
     * waiting for its dates (companies plan, Phase 3b) is ahead, not gone,
     * and a screen that asks for `date_start >= today` silently loses it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAheadOrUndated($query)
    {
        return $query->where(self::aheadOrUndatedFilter());
    }

    /**
     * The same, as a closure for `whereHas('departure', …)`, where the
     * scope is out of Larastan's sight.
     */
    public static function aheadOrUndatedFilter(): \Closure
    {
        return fn ($q) => $q->where(fn ($w) => $w->whereNull('date_start')->orWhere('date_start', '>=', now()->startOfDay()));
    }

    /**
     * Not over by the given day: it ends on or after it, or has no dates
     * yet. For the office's "recent and upcoming" lists.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNotOverBy($query, \DateTimeInterface $day)
    {
        return $query->where(self::notOverByFilter($day));
    }

    /** The same, as a closure for `whereHas('departure', …)`. */
    public static function notOverByFilter(\DateTimeInterface $day): \Closure
    {
        return fn ($q) => $q->where(fn ($w) => $w->whereNull('date_end')->orWhere('date_end', '>=', $day));
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return ['package'];
    }
}
