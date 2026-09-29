<?php

namespace App\Models;

use App\Services\Stays\Availability;
use App\Support\Audience;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
        'partner_id', 'type', 'kind', 'slug', 'island', 'atoll', 'latitude', 'longitude',
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

    // ── What a guest is actually renting — §16.5 ─────────────────────────
    //
    // `type` picks the door the property is sold through; `kind` says what
    // is behind it. A Malé rental may be one room or a whole flat, and a
    // guest choosing between them needs to know which.

    public const KIND_GUESTHOUSE = 'guesthouse';

    public const KIND_WHOLE_HOME = 'whole_home';

    public const KIND_APARTMENT = 'apartment';

    public const KIND_PRIVATE_ROOM = 'private_room';

    /** @var list<string> */
    public const KINDS = [self::KIND_GUESTHOUSE, self::KIND_WHOLE_HOME, self::KIND_APARTMENT, self::KIND_PRIVATE_ROOM];

    /**
     * What a guest reads for a kind, in their language.
     *
     * Each key written out whole rather than built from the value, so the
     * translation guard can see every one of them — a key assembled at
     * runtime is a key nothing checks.
     */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_WHOLE_HOME => __('messages.Whole home'),
            self::KIND_APARTMENT => __('messages.Apartment'),
            self::KIND_PRIVATE_ROOM => __('messages.Private room'),
            default => __('messages.Guesthouse'),
        };
    }

    // ── Whether Rihla has approved the listing — §16.6 ───────────────────
    //
    // Not fillable. A host edits their listing; only Rihla approves it, and
    // a form that forgot to strip this would let a host publish themselves.

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const WITHDRAWN = 'withdrawn';

    /** @var list<string> */
    public const APPROVALS = [self::DRAFT, self::PENDING, self::APPROVED, self::CHANGES_REQUESTED, self::WITHDRAWN];

    /**
     * Slugs a property may never take — §15.4 (Phase 9.4).
     *
     * A property lives at `/{locale}/stays/{slug}`, which sits directly
     * under the three strand routes. Laravel matches in declaration order,
     * so a property slugged `guesthouses` would never be reachable: the
     * strand route would answer first, the property page would simply not
     * exist, and nothing would report it — a shared link that silently goes
     * somewhere else is worse than one that 404s.
     *
     * The short URL is deliberate. `rihla.mv/ar/stays/maafushi-view` is the
     * whole sales conversation over WhatsApp, which is what the owner asked
     * the share kit for, and a handful of reserved words is a small price.
     *
     * `hosts`, `book` and `search` are the marketplace's own pages — §16,
     * Phase 12.4 — reserved now, before any route claims them, so that no
     * listing minted in the meantime is sitting on the address one of them
     * will need. `StaysPublicPagesTest` checks this list against every
     * literal route under `/stays/`, so a new route cannot be added
     * without it.
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = ['guesthouses', 'island-holidays', 'rooms', 'hosts', 'atolls', 'book', 'search', 'review'];

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
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
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
        'approval' => self::DRAFT,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $property): void {
            if (blank($property->slug)) {
                $property->slug = Str::slug($property->getTranslation('name', 'en'));
            }

            // A guesthouse actually called "Rooms" would otherwise mint a
            // slug that the strand route shadows. Suffixed rather than
            // refused: the name is legitimate, and a property that cannot
            // be saved because of a routing detail is a worse answer than
            // one whose URL reads `rooms-stay`.
            if (in_array($property->slug, self::RESERVED_SLUGS, true)) {
                $property->slug .= '-stay';
            }
        });

        // The database cascades the rows; only the model can take the
        // files. A property deleted with its photographs still on disk is
        // the leak AGENTS.md records under "a generator with no counterpart".
        static::deleting(function (self $property): void {
            $property->photos()->get()->each->delete();
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

    /**
     * Every season priced anywhere in this building — §15.4 (Phase 9.2).
     *
     * Through the rooms, because a rate belongs to a room type and not to
     * the property. Exposed on the property anyway because that is how a
     * guesthouse owner thinks about it: "December is this much", across the
     * whole place. Setting the same dates once per room is how one of them
     * ends up a month out.
     *
     * @return HasManyThrough<Rate, RoomType, $this>
     */
    public function rates(): HasManyThrough
    {
        return $this->hasManyThrough(Rate::class, RoomType::class)->orderBy('starts_on');
    }

    /**
     * Every night anywhere in this building that is not for sale.
     *
     * @return HasManyThrough<BlockedDate, RoomType, $this>
     */
    public function blockedDates(): HasManyThrough
    {
        return $this->hasManyThrough(BlockedDate::class, RoomType::class)->orderBy('date');
    }

    /** @return HasMany<PropertyPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(PropertyPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Extras a guest can add when booking — §16 Phase 15.
     *
     * @return HasMany<PropertyAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(PropertyAddon::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Other sites' calendars for this building's rooms — §16 Phase 16.
     *
     * @return HasManyThrough<CalendarFeed, RoomType, $this>
     */
    public function calendarFeeds(): HasManyThrough
    {
        return $this->hasManyThrough(CalendarFeed::class, RoomType::class);
    }

    /**
     * Promotions and long-stay discounts — §16 Phase 16.
     *
     * @return HasMany<StayDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(StayDiscount::class);
    }

    /** @return HasMany<PropertyUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(PropertyUnit::class)->orderBy('sort_order')->orderBy('label');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<Stay, $this> */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class)->latest('check_in');
    }

    /** @param Builder<$this> $query */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * What a guest may see — §16.7.
     *
     * Published **and** approved, from a host who is active **and**
     * verified. One scope, read by the search, the strand lists and the
     * listing page alike: a listing hidden from search but reachable on a
     * strand, or the other way round, is a listing that is half-suspended,
     * which is not a state anybody chose.
     *
     * Every row that existed before §16 was backfilled approved and every
     * partner verified and active, so nothing that was visible stops being.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeListable($query)
    {
        return $query
            ->where('is_published', true)
            ->where('approval', self::APPROVED)
            ->whereHas('partner', fn (Builder $partner) => $partner
                ->where('status', Partner::STATUS_ACTIVE)
                ->where('verification', Partner::VERIFIED));
    }

    /**
     * At least one room this audience can buy — the SQL form of
     * {@see Availability::offers()}, which the two
     * must keep agreeing with.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOffering($query, string $audience)
    {
        return $query->whereHas('roomTypes', function (Builder $rooms) use ($audience): void {
            if ($audience !== Audience::LOCAL) {
                return;
            }

            $rooms->where(fn (Builder $room) => $room
                ->whereNotNull('local_rate_minor')
                ->orWhereHas('rates', fn (Builder $rates) => $rates->where('audience', Audience::LOCAL)));
        });
    }

    /**
     * Would a guest of this audience see it at all?
     *
     * The same rule as {@see scopeListable()}, asked of one row that is
     * already loaded — the listing page has the model, not a query.
     */
    public function isListable(): bool
    {
        return $this->is_published
            && $this->approval === self::APPROVED
            && $this->partner !== null
            && $this->partner->status === Partner::STATUS_ACTIVE
            && $this->partner->verification === Partner::VERIFIED;
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
     * The "from" price for one audience — §16.7.
     *
     * A tourist's is {@see cheapestRate()}, unchanged. A local's is the
     * cheapest local base rate in rufiyaa, and null when the only local
     * prices are seasons: a season is a price for some nights, and a card
     * reading "from" one would be quoting a number most dates do not have.
     */
    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * The visible reviews' average and count, as two query columns —
     * `review_average` and `review_count` — for a page listing many.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithRating(Builder $query): Builder
    {
        return $query
            ->withCount(['reviews as review_count' => fn (Builder $reviews) => Review::onlyVisible($reviews)])
            ->withAvg(['reviews as review_average' => fn (Builder $reviews) => Review::onlyVisible($reviews)], 'rating');
    }

    /**
     * The rating a guest is shown, or null before the first visible review
     * — §16.11. A hidden review counts for nothing.
     *
     * @return array{average: float, count: int}|null
     */
    public function rating(): ?array
    {
        if (array_key_exists('review_count', $this->attributes)) {
            $count = (int) $this->attributes['review_count'];
            $average = (float) ($this->attributes['review_average'] ?? 0);
        } else {
            $count = $this->reviews()->visible()->count();
            $average = (float) $this->reviews()->visible()->avg('rating');
        }

        return $count > 0 ? ['average' => round($average, 1), 'count' => $count] : null;
    }

    /**
     * The host's own page, when there is one a guest may open — §16.8.
     * Eager-load `partner.page` where this is asked of many.
     */
    public function hostPageUrl(): ?string
    {
        $partner = $this->partner;

        return $partner !== null && $partner->isListed() && $partner->page?->isPublished()
            ? route('stays.host', ['partner' => $partner->slug])
            : null;
    }

    public function cheapestRateFor(string $audience): ?Money
    {
        if ($audience !== Audience::LOCAL) {
            return $this->cheapestRate();
        }

        $minor = $this->roomTypes
            ->filter(fn (RoomType $room): bool => $room->local_rate_minor !== null && $room->local_rate_minor > 0)
            ->min('local_rate_minor');

        return $minor === null ? null : Money::ofMinor((int) $minor, Audience::currencyAt($this, Audience::LOCAL));
    }

    /**
     * Is the description a reader in this locale will actually get written
     * in their language?
     *
     * §15.4 asks for a property with no Arabic to fall back to English
     * **and say so** — never a blank, never a machine translation. That
     * second half is the point: a page silently in the wrong language
     * reads as a site that does not care, where one that says "we have not
     * translated this yet" reads as one that does and has not got to it.
     *
     * Judged on `summary` alone, and deliberately. It is the field every
     * listing card and every share preview shows, so it is the one a reader
     * meets first — and requiring all five to be present would flag a
     * perfectly good Arabic page for want of a translated house rule.
     */
    public function isTranslatedInto(string $locale): bool
    {
        return $locale === 'en'
            || filled($this->getTranslation('summary', $locale, false));
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
