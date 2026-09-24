<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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

    /**
     * The cache follows the data, not the screen.
     *
     * It used to be cleared by four hand-written `forgetCache()` calls in
     * the two Blade controllers, and nowhere else — so a section or a
     * feature saved by any other route (the staff panel, a seeder, tinker)
     * left the homepage showing the old words for up to an hour, with no
     * error and nothing to suggest a save had not taken. Tying it to the
     * model means a third way of writing one cannot be added without it.
     *
     * The listener must return nothing. `Cache::forget()` returns false
     * when the key is not cached, and a model-event listener that returns
     * false halts every later listener for that event — the note `Package`
     * carries for the sitemap, for the same reason.
     */
    protected static function booted(): void
    {
        $forget = static function (): void {
            self::forgetCache();
        };

        static::saved($forget);
        static::deleted($forget);

        // The photograph, when it is replaced or the section deleted. The
        // Blade controller always did this by hand; the staff panel's file
        // upload does not, so without it every replaced picture would stay
        // on a disk that is a fixed per-account allowance. Tied to the model
        // for the same reason as the cache — see AGENTS.md on a generator
        // with no counterpart.
        static::updated(static function (self $model): void {
            if ($model->wasChanged('image_path')) {
                self::forgetImage($model->getOriginal('image_path'));
            }
        });

        static::deleted(static function (self $model): void {
            self::forgetImage($model->image_path);
        });
    }

    /**
     * Remove an uploaded picture that nothing points at any more.
     *
     * Each upload through either panel gets a unique stored name, so the
     * file belongs to this row alone — which is what makes deleting it
     * safe here when it would not be for a shared asset.
     */
    private static function forgetImage(mixed $path): void
    {
        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * The one section, created on first use with English defaults only.
     *
     * Moved here from the Blade controller's `index()`, with its rule
     * intact. That lookup used to find a section for the *panel's* locale
     * and create one if there was none — and the Dhivehi one it created
     * carried two hard-coded Thaana sentences nobody had written, so an
     * editor opening the screen with the panel in Dhivehi silently
     * published machine-generated Dhivehi to the homepage. English only;
     * the Dhivehi half is typed by a person or left blank, and blank falls
     * back to English.
     */
    public static function singleton(): self
    {
        return self::where('is_active', true)->first()
            ?? self::first()
            ?? self::create([
                'title' => ['en' => 'Why Choose Rihla'],
                'subtitle' => ['en' => 'Discover the unique advantages that make us your perfect travel partner'],
                'is_active' => true,
            ]);
    }

    /** @return HasMany<WhyFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(WhyFeature::class)->orderBy('sort_order');
    }
}
