<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'slug', 'title', 'summary', 'details', 'inclusions', 'exclusions',
        'nights', 'accessibility_rating', 'accessibility_notes',
        'cover_image', 'is_published', 'sort_order',
    ];

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
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'inclusions' => 'array',
        'exclusions' => 'array',
        'accessibility_notes' => 'array',
    ];

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
}
