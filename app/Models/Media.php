<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use HasFactory;
    
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

    public function getThumbnailUrlAttribute()
    {
        if ($this->type === self::TYPE_VIDEO && $this->video_url) {
            // YouTube
            if (str_contains($this->video_url, 'youtube.com') || str_contains($this->video_url, 'youtu.be')) {
                $videoId = $this->video_id;
                if ($videoId) {
                    return "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                }
            }
            
            // Vimeo
            if (str_contains($this->video_url, 'vimeo.com')) {
                $videoId = $this->getVimeoId();
                if ($videoId) {
                    // Note: Vimeo requires API call for thumbnails, using placeholder for now
                    return "https://via.placeholder.com/400x225/2563eb/ffffff?text=Video+Thumbnail";
                }
            }
            
            // Facebook
            if (str_contains($this->video_url, 'facebook.com')) {
                return "https://via.placeholder.com/400x225/1877f2/ffffff?text=Facebook+Video";
            }
            
            // Instagram
            if (str_contains($this->video_url, 'instagram.com')) {
                return "https://via.placeholder.com/400x225/e4405f/ffffff?text=Instagram+Video";
            }
            
            // TikTok
            if (str_contains($this->video_url, 'tiktok.com')) {
                return "https://via.placeholder.com/400x225/000000/ffffff?text=TikTok+Video";
            }
            
            // Generic video placeholder
            return "https://via.placeholder.com/400x225/6b7280/ffffff?text=Video";
        }

        return $this->thumb_path;
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
