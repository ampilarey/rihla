<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * A building Rihla sells nights in — §15.4 (Phase 9.1).
 *
 * A guesthouse on an island, or (from Phase 11) a rental in Malé. The
 * distinction is `type`, and it is one column rather than two engines
 * because what a customer does — pick dates, pick a room, pay a deposit —
 * is the same in both.
 *
 * The booking policy lives on the row, not in a constant. §15.2 decision 2
 * gives the defaults (30% deposit, balance 14 days before, free cancellation
 * until 14 days before), but a partner may have signed something else, and
 * the page has to print the policy the stay will actually be held to. A
 * constant would move later and reprint a policy the customer never agreed
 * to — the mistake `AGENTS.md` records under "a migration must never write a
 * constant", in its other form.
 */
class Property extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'partner_id', 'type', 'slug', 'island',
        'name', 'summary', 'description', 'house_rules', 'check_in_instructions',
        'amenities', 'check_in_time', 'check_out_time', 'cover_image',
        'instant_book', 'min_nights', 'currency',
        'deposit_pct', 'balance_days_before', 'free_cancel_days',
        'is_published', 'sort_order',
    ];

    // ── What kind of building this is ────────────────────────────────────

    /** An island guesthouse, marketed to foreign visitors. Phase 9. */
    public const GUESTHOUSE = 'guesthouse';

    /** A nightly room in Malé, instant-book. Phase 11 sells these. */
    public const RENTAL = 'rental';

    /** @var list<string> */
    public const TYPES = [self::GUESTHOUSE, self::RENTAL];

    /**
     * Per docs/adr/0001-how-content-is-translated.md. `amenities` holds a
     * list per language, the same shape as a package's inclusions.
     *
     * The slug is not translatable: one property, one URL per locale, with
     * the locale already a path segment in front of it — which is exactly
     * what makes the share kit of §15.4 a single link per language.
     *
     * @var array<int, string>
     */
    public array $translatable = [
        'name', 'summary', 'description', 'house_rules', 'check_in_instructions', 'amenities',
    ];

    /**
     * `amenities` is cast to array for the reason Package records: spatie
     * merges the cast when it initialises, and without it static analysis
     * reads the json column as a string and calls every is_array() guard
     * below dead code. The guard stays regardless — a translated attribute
     * with nothing stored comes back as an empty *string*, not an array.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amenities' => 'array',
        'instant_book' => 'boolean',
        'min_nights' => 'integer',
        'deposit_pct' => 'integer',
        'balance_days_before' => 'integer',
        'free_cancel_days' => 'integer',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    // ── The house booking policy — §15.2 decision 2 ──────────────────────

    /** Taken when the partner confirms, not when the customer asks. */
    public const DEFAULT_DEPOSIT_PCT = 30;

    /** The rest falls due this many days before check-in. */
    public const DEFAULT_BALANCE_DAYS_BEFORE = 14;

    /** Cancel before this many days and the deposit comes back. */
    public const DEFAULT_FREE_CANCEL_DAYS = 14;

    /**
     * Carried in memory as well as in the column default.
     *
     * The database default alone is not enough: a Property just created
     * reads `deposit_pct` as null until something refreshes it, and code
     * that works out a deposit from a model it has just made would get
     * nothing — a 0% deposit on a stay, silently. The column default still
     * exists for rows written outside Eloquent, and
     * `StaysFoundationTest` holds the two to the same numbers.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => self::GUESTHOUSE,
        'currency' => 'USD',
        'deposit_pct' => self::DEFAULT_DEPOSIT_PCT,
        'balance_days_before' => self::DEFAULT_BALANCE_DAYS_BEFORE,
        'free_cancel_days' => self::DEFAULT_FREE_CANCEL_DAYS,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $property): void {
            if (blank($property->slug)) {
                $property->slug = Str::slug($property->getTranslation('name', 'en'));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return HasMany<RoomType, $this> */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class)->orderBy('sort_order');
    }

    /** @param Builder<$this> $query */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * @param  Builder<$this>  $query
     * @param  list<string>  $types
     */
    public function scopeOfType($query, array $types)
    {
        return $query->whereIn('type', $types);
    }

    /** @return list<string> */
    public function getAmenityListAttribute(): array
    {
        return is_array($this->amenities) ? array_values($this->amenities) : [];
    }

    /**
     * The cheapest room in the building, for the "from" price on a card.
     *
     * Null when the property has no room types yet, rather than zero: a card
     * reading "from USD 0" is worse than a card with no price on it, and the
     * difference between "free" and "not priced yet" is not one a customer
     * should have to work out.
     */
    public function cheapestRate(): ?Money
    {
        $minor = $this->roomTypes
            ->filter(fn (RoomType $room): bool => $room->base_rate_minor > 0)
            ->min('base_rate_minor');

        return $minor === null ? null : Money::ofMinor((int) $minor, $this->currency);
    }

    /**
     * Is this property bookable without a partner saying yes first?
     *
     * Both halves matter. An unpublished property is not bookable at all,
     * and a published one is only instant if somebody has explicitly said
     * so — §15.2 decision 1 makes request-first the default because
     * availability Rihla has not been given is not Rihla's to promise.
     */
    public function isInstantBookable(): bool
    {
        return $this->is_published && $this->instant_book;
    }
}
