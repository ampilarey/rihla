<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dated run of a Package.
 *
 * Carries everything that changes between runs: the dates, the airline, the
 * hotels, the day-by-day itinerary, the prices and the seats.
 */
class Departure extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id', 'trip_id', 'date_start', 'date_end', 'airline',
        'capacity_total', 'capacity_held', 'capacity_confirmed',
        'status', 'is_published',
    ];

    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'capacity_total' => 'integer',
        'capacity_held' => 'integer',
        'capacity_confirmed' => 'integer',
        'is_published' => 'boolean',
    ];

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_CURRENT = 'current';

    public const STATUS_PAST = 'past';

    public const STATUSES = [self::STATUS_UPCOMING, self::STATUS_CURRENT, self::STATUS_PAST];

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

    /** @return HasMany<ItineraryItem, $this> */
    public function itinerary(): HasMany
    {
        return $this->hasMany(ItineraryItem::class)->orderBy('day_number');
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

    // ── Price ────────────────────────────────────────────────────────────

    /** The cheapest tier — what a "from" price means. */
    public function getLeadPriceAttribute(): ?Money
    {
        $tier = $this->priceTiers->sortBy('amount_minor')->first();

        return $tier?->money();
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
        return (int) $this->date_start->diffInDays($this->date_end);
    }

    /** Whole days until departure; negative once it has left. */
    public function getDaysUntilAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->date_start->startOfDay(), false);
    }

    /** @param Builder<$this> $query */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /** @param Builder<$this> $query */
    public function scopeUpcoming($query)
    {
        return $query->where('date_start', '>=', now()->startOfDay());
    }
}
