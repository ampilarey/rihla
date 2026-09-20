<?php

namespace App\Models;

use App\Models\Concerns\EditorialGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * A place a pilgrim visits — §7.2.
 *
 * ## It carries the same editorial gate as an article
 *
 * A location page asserts history, significance and etiquette. Those are
 * religious claims, so they pass through {@see EditorialGate}: sources, a
 * named scholar, approval before publication, a reason for withdrawal.
 * Nothing about §7.2 makes them lighter claims than §7.1's.
 *
 * ## And one rule of its own
 *
 * A misconception with no source is refused at approval. §7.2's whole
 * argument for the feature is that naming what pilgrims are wrongly told
 * prevents the innovations they are warned about — which only holds if the
 * correction is better sourced than the belief. An unsourced correction is
 * one more thing to take on trust, from the same pilgrim's point of view.
 *
 * ## Coordinates, and deliberately no embedded map
 *
 * §11.4 wants Google Maps and nobody has supplied an API key. An unkeyed
 * embed renders a grey rectangle stamped "for development purposes only"
 * across a page about the Prophet's mosque, so the page links out to the
 * map application the visitor's phone already has. {@see mapUrl()} is a
 * `geo:`-style https link every phone resolves; the embed arrives with the
 * key and nothing above it changes.
 */
class ZiyarahLocation extends Model
{
    // Aliased so the override below can call the shared check first. A
    // trait method is not a parent method, so `parent::` cannot reach it.
    use EditorialGate {
        whyNotApprovable as editorialRefusal;
    }
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'slug', 'name', 'summary', 'history', 'significance', 'etiquette', 'best_time',
        'city', 'latitude', 'longitude', 'sort_order',
    ];

    /** @var array<int, string> */
    public array $translatable = ['name', 'summary', 'history', 'significance', 'etiquette', 'best_time'];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    public const MAKKAH = 'makkah';

    public const MADINAH = 'madinah';

    public const OTHER = 'other';

    /** @var list<string> */
    public const CITIES = [self::MAKKAH, self::MADINAH, self::OTHER];

    protected function editorialSubject(): string
    {
        return 'location';
    }

    /** @return HasMany<LocationMisconception, $this> */
    public function misconceptions(): HasMany
    {
        return $this->hasMany(LocationMisconception::class)->orderBy('sort_order');
    }

    /**
     * The gate, plus the one rule §7.2 adds.
     *
     * Overriding this is enough: {@see EditorialGate::approve()} throws
     * whatever this returns, so the screen and the model cannot disagree
     * about why a location is not ready.
     */
    public function whyNotApprovable(): ?string
    {
        if (($refusal = $this->editorialRefusal()) !== null) {
            return $refusal;
        }

        $unsourced = $this->misconceptions()->doesntHave('references')->count();

        if ($unsourced > 0) {
            return $unsourced === 1
                ? 'One correction here has no source. A correction a pilgrim is asked to believe over what they were told needs to be the better-sourced of the two, or it is one more thing to take on trust.'
                : $unsourced.' corrections here have no source. A correction a pilgrim is asked to believe over what they were told needs to be the better-sourced of the two, or it is one more thing to take on trust.';
        }

        return null;
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * A link out to whatever map application the phone already has.
     *
     * Not an embed. See the class comment: an unkeyed Google map is worse
     * than no map on a page like this one.
     */
    public function mapUrl(): ?string
    {
        if (! $this->hasCoordinates()) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='
            .rawurlencode($this->latitude.','.$this->longitude);
    }

    public function cityLabel(): string
    {
        return match ($this->city) {
            self::MAKKAH => 'Makkah',
            self::MADINAH => 'Madinah',
            self::OTHER => 'Elsewhere',
            default => 'Unknown',
        };
    }

    /**
     * @param  Builder<ZiyarahLocation>  $query
     * @return Builder<ZiyarahLocation>
     */
    public function scopeInCity(Builder $query, string $city): Builder
    {
        return $query->where('city', $city);
    }
}
