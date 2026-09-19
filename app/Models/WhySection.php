<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Translatable\HasTranslations;

class WhySection extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'title',
        'subtitle',
        'image_path',
        'primary_cta_text',
        'primary_cta_url',
        'secondary_cta_text',
        'secondary_cta_url',
        'title_color',
        'subtitle_color',
        'primary_cta_bg_color',
        'primary_cta_text_color',
        'secondary_cta_bg_color',
        'secondary_cta_text_color',
        'is_active',
    ];

    /**
     * Stored as `{"en": …, "dv": …}` and read back in the request's locale,
     * falling back to English. There used to be one section row per language,
     * each with its own set of features, related to the other's by nothing —
     * so changing a feature's icon or its order meant doing it twice.
     *
     * The CTA URLs are not translated; see
     * docs/adr/0001-how-content-is-translated.md.
     *
     * @var array<int, string>
     */
    public array $translatable = ['title', 'subtitle', 'primary_cta_text', 'secondary_cta_text'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The homepage caches the active section and its features for an hour.
     *
     * One key, not one per locale: the section is one row in both languages
     * now, and the cached models carry both. `config/cache.php` allowlists
     * these classes for serialisation — see its `serializable_classes`.
     */
    public const CACHE_KEY = 'why_section_active';

    /** Call after any write that the homepage would otherwise show stale. */
    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function features(): HasMany
    {
        return $this->hasMany(WhyFeature::class)->orderBy('sort_order');
    }
}
