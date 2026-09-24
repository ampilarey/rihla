<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

class WhyFeature extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'why_section_id',
        'icon',
        'title',
        'text',
        'image_path',
        'link_url',
        'link_text',
        'background_color',
        'sort_order',
        'is_active',
    ];

    /**
     * Stored as `{"en": …, "dv": …}` and read back in the request's locale,
     * falling back to English.
     *
     * These three cards had no translation mechanism at all: the Dhivehi
     * homepage showed a second section's second set of features, or — once
     * the fabricated Dhivehi section was deleted — the English ones.
     *
     * @var array<int, string>
     */
    public array $translatable = ['title', 'text', 'link_text'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * A feature is cached inside its section's homepage entry, so changing
     * one has to clear that entry too — see {@see WhySection::booted()}.
     * Returns nothing for the reason given there.
     */
    protected static function booted(): void
    {
        $forget = static function (): void {
            WhySection::forgetCache();
        };

        static::saved($forget);
        static::deleted($forget);

        // The photograph, when it is replaced or the feature deleted. The
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

    /** @return BelongsTo<WhySection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(WhySection::class, 'why_section_id');
    }
}
