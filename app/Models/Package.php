<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected $casts = [
        'nights' => 'integer',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
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
