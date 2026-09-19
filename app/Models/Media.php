<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

class Media extends Model
{
    use HasFactory, HasTranslations;

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName()
    {
        return 'id';
    }

    protected $fillable = [
        'trip_id',
        'type',
        'title',
        'caption',
        'file_path',
        'video_url',
        'thumb_path',
        'sort_order',
        'is_published',
    ];

    /**
     * Stored as `{"en": …, "dv": …}` and read back in the request's locale,
     * falling back to English. The last content table to get this: until now
     * a Dhivehi visitor read English captions with no way to change that.
     *
     * `file_path`, `thumb_path` and `video_url` stay single values — a
     * photograph is the same photograph in both languages. See
     * docs/adr/0001-how-content-is-translated.md.
     *
     * @var array<int, string>
     */
    public array $translatable = ['title', 'caption'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_published' => 'boolean',
    ];

    const TYPE_PHOTO = 'photo';

    const TYPE_VIDEO = 'video';

    const TYPES = [
        self::TYPE_PHOTO,
        self::TYPE_VIDEO,
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopePhotos($query)
    {
        return $query->byType(self::TYPE_PHOTO);
    }

    public function scopeVideos($query)
    {
        return $query->byType(self::TYPE_VIDEO);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function getVideoIdAttribute()
    {
        if ($this->type === self::TYPE_VIDEO && $this->video_url) {
            // Extract video ID from YouTube URL
            $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i';
            if (preg_match($pattern, $this->video_url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * A thumbnail to show before the video is played, or null.
     *
     * This used to return `https://via.placeholder.com/...` for Vimeo,
     * Facebook, Instagram and TikTok. Two things were wrong with that. The
     * service shut down, so the URL resolves to nothing; and `img-src` allows
     * `'self'`, `data:` and YouTube's thumbnail hosts only, so the browser
     * refused it and logged a violation before it could even fail. Every
     * non-YouTube video in the gallery rendered a broken image.
     *
     * YouTube publishes thumbnails at a predictable URL and that host is in
     * the policy, so those still work. For everything else the answer is an
     * uploaded thumbnail if there is one, and otherwise nothing — the gallery
     * shows its own placeholder with a play button, which is honest about
     * having no picture rather than pretending to have one.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if ($this->type === self::TYPE_VIDEO && $this->video_id) {
            return "https://img.youtube.com/vi/{$this->video_id}/hqdefault.jpg";
        }

        return $this->thumb_path ? Storage::url($this->thumb_path) : null;
    }

    public function getVimeoId()
    {
        if (str_contains($this->video_url, 'vimeo.com')) {
            preg_match('/vimeo\.com\/(\d+)/', $this->video_url, $matches);

            return $matches[1] ?? null;
        }

        return null;
    }
}
