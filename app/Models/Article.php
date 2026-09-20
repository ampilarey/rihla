<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * One article.
 *
 * Published at a time rather than by a flag: an article written today can be
 * dated Friday and will appear then without anybody remembering to press a
 * button, and a reader and a crawler both want the date anyway.
 */
class Article extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = ['slug', 'title', 'excerpt', 'body', 'cover_image', 'author_id', 'published_at'];

    /** @var array<int, string> */
    public array $translatable = ['title', 'excerpt', 'body'];

    /** @var array<string, string> */
    protected $casts = ['published_at' => 'datetime'];

    protected static function booted(): void
    {
        // The sitemap lists every published article and is cached for an
        // hour. Must return nothing — a listener returning false halts the
        // rest of them.
        $bustSitemap = function (): void {
            Cache::forget('sitemap.xml');
        };

        static::saved($bustSitemap);
        static::deleted($bustSitemap);

        static::creating(function (self $article): void {
            if (blank($article->slug)) {
                $article->slug = Str::slug($article->getTranslation('title', 'en'));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Person, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_id');
    }

    /**
     * Published means dated, and dated in the past.
     *
     * A null date is a draft; a future date is scheduled. Both are invisible,
     * and the same scope covers the listing, the article page and the
     * sitemap, so they cannot disagree about what is live.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function getIsPublishedAttribute(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }
}
