<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * What Rihla sells, separately from when it runs.
 *
 * The `trips` table made the product and the date the same row, so running
 * the same fourteen-night package twice meant retyping it and getting a
 * second URL. A package is written once; each time it runs is a Departure.
 */
class Package extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'slug', 'type', 'property_id', 'title', 'summary', 'details', 'inclusions', 'exclusions',
        'nights', 'flexible_dates', 'min_nights',
        'extension_destination', 'extension_nights', 'extension_details',
        'accessibility_rating', 'accessibility_notes',
        'cover_image', 'is_published', 'sort_order',
    ];

    // ── What kind of product this is — §15.3 (Phase 8.7) ─────────────────

    /** The pilgrimage itself. Every package that existed before this column. */
    public const UMRAH = 'umrah';

    /**
     * An Umrah with an extension segment — a few nights in Istanbul on the
     * way home. Still an Umrah product, still under the Umrah menu: the
     * owner's correction in §15.1, which exists because this was about to
     * become a third tab called "Holidays".
     */
    public const UMRAH_PLUS = 'umrah_plus';

    /**
     * A weekend on a local island for a Maldivian family. Sells nothing
     * yet — Phase 10 builds it on this engine — and it is a *guesthouse*
     * product, so it belongs under Stays and never under Umrah.
     */
    public const ISLAND_HOLIDAY = 'island_holiday';

    /** @var list<string> */
    public const TYPES = [self::UMRAH, self::UMRAH_PLUS, self::ISLAND_HOLIDAY];

    /** The two that belong under the Umrah menu, as opposed to Stays. */
    public const UMRAH_TYPES = [self::UMRAH, self::UMRAH_PLUS];

    // ── Who it is sold to — §15.5 (Phase 10) ─────────────────────────────

    /** Anybody performing Umrah, of any nationality. */
    public const PILGRIMS = 'pilgrims';

    /** Maldivian families, going to a local island. */
    public const LOCALS = 'locals';

    /**
     * Per docs/adr/0001-how-content-is-translated.md. `inclusions`,
     * `exclusions` and `accessibility_notes` hold a list per language —
     * {"en": [...], "dv": [...]} — the same shape as a guide step's
     * checklist.
     *
     * The slug is not translatable: one package, one URL, with the locale
     * already a path segment in front of it.
     *
     * @var array<int, string>
     */
    public array $translatable = [
        'title', 'summary', 'details', 'inclusions', 'exclusions', 'accessibility_notes',
        'extension_details',
    ];

    /**
     * The three list-valued translatable columns are cast to `array`, the
     * same way a guide step's checklist is. Two reasons, and the second is
     * not cosmetic: spatie merges the cast when it initialises, and without
     * it static analysis reads the json column as a string and reports every
     * is_array() guard below as dead.
     *
     * The guards stay regardless. A translated attribute with nothing stored
     * for the current locale comes back as an empty *string*, not null and
     * not an empty array — which is how a string once reached code that
     * foreach'd over it.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'nights' => 'integer',
        'flexible_dates' => 'boolean',
        'min_nights' => 'integer',
        'extension_nights' => 'integer',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'inclusions' => 'array',
        'exclusions' => 'array',
        'accessibility_notes' => 'array',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['type' => self::UMRAH];

    protected static function booted(): void
    {
        // The sitemap lists every published package and is cached for an
        // hour. Without this, publishing one would leave it out of the
        // sitemap for up to an hour after it went live — the same gap Trip
        // already closes.
        //
        // Must return nothing: Cache::forget() returns false when the key is
        // not cached, and a model-event listener returning false halts every
        // later listener for that event.
        $bustSitemap = function (): void {
            Cache::forget('sitemap.xml');
        };

        static::saved($bustSitemap);
        static::deleted($bustSitemap);

        static::creating(function (self $package): void {
            if (blank($package->slug)) {
                $package->slug = Str::slug($package->getTranslation('title', 'en'));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Departure, $this> */
    public function departures(): HasMany
    {
        return $this->hasMany(Departure::class)->orderBy('date_start');
    }

    /** @return HasMany<Departure, $this> */
    public function publishedDepartures(): HasMany
    {
        return $this->departures()->where('is_published', true);
    }

    /**
     * Every room type any published departure of this package prices.
     *
     * The union, in price-list order, because the booking form has to offer
     * something before a departure is chosen. Whether *that* departure
     * offers the room is re-checked on submit — a room the chosen date does
     * not price comes back as an error rather than as a price of zero.
     *
     * @return list<string>
     */
    public function occupanciesOffered(): array
    {
        $offered = $this->publishedDepartures
            ->flatMap(fn (Departure $departure): array => $departure->priceTiers->pluck('occupancy')->all())
            ->unique();

        return array_values(array_filter(
            PriceTier::OCCUPANCIES,
            fn (string $occupancy): bool => $offered->contains($occupancy),
        ));
    }

    /** @return list<string> */
    public function getInclusionListAttribute(): array
    {
        return is_array($this->inclusions) ? array_values($this->inclusions) : [];
    }

    /** @return list<string> */
    public function getExclusionListAttribute(): array
    {
        return is_array($this->exclusions) ? array_values($this->exclusions) : [];
    }

    /** @return list<string> */
    public function getAccessibilityNoteListAttribute(): array
    {
        return is_array($this->accessibility_notes) ? array_values($this->accessibility_notes) : [];
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

    /**
     * Who this is sold to — **derived, never stored**.
     *
     * The plan lists an `audience` column. It is not in the schema,
     * because `type` already answers the question: an Umrah is sold to
     * pilgrims and an island holiday to Maldivian families, and nothing at
     * Rihla is both. A second column stating what the first one states is
     * a second copy to go stale, which `AGENTS.md` records at length for a
     * palette with more than one source of truth.
     *
     * If a product ever genuinely needs an audience the type cannot imply,
     * that is the day this becomes a column — and it will be one place to
     * change rather than every read.
     */
    public function audience(): string
    {
        return $this->type === self::ISLAND_HOLIDAY ? self::LOCALS : self::PILGRIMS;
    }

    /**
     * An island holiday asks nothing of a government — §15.5 (Phase 10).
     *
     * A Maldivian family catching the Thursday ferry to Ukulhas needs no
     * passport, no visa and no Umrah permit, and asking for any of them is
     * not a harmless extra field: it is a form somebody abandons, and a
     * readiness board that reads "not ready" for a family who are entirely
     * ready to go.
     *
     * Expressed as a property of the *package* rather than checked at each
     * of the four places that care, so a fifth cannot be added without
     * meeting it.
     */
    public function needsTravelDocuments(): bool
    {
        return $this->type !== self::ISLAND_HOLIDAY;
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Sold for dates of the customer's choosing rather than a fixed
     * departure.
     *
     * Both halves matter: a package marked flexible with no minimum would
     * accept a one-night booking on a boat that only runs on Thursdays.
     */
    public function isFlexible(): bool
    {
        return $this->flexible_dates;
    }

    public function minimumNights(): int
    {
        return max(1, (int) $this->min_nights);
    }

    /**
     * Does this package have an extension worth a block on its page?
     *
     * The type alone is not enough. A package can be marked Umrah Plus and
     * saved before anybody has typed where the extension goes, and an
     * "Extension" heading over an empty box reads as a broken page rather
     * than an unfinished one.
     */
    public function hasExtension(): bool
    {
        return $this->type === self::UMRAH_PLUS
            && filled($this->extension_destination);
    }
}
