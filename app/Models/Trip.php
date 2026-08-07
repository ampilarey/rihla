<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'locale',
        'title',
        'title_dv',
        'slug',
        'date_start',
        'date_end',
        'location',
        'location_dv',
        'summary',
        'summary_dv',
        'details',
        'details_dv',
        'price_from_mvr',
        'status',
        'cover_image',
        'is_published',
    ];

    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'price_from_mvr' => 'integer',
        'is_published' => 'boolean',
    ];

    const STATUS_CURRENT = 'current';

    const STATUS_UPCOMING = 'upcoming';

    const STATUS_PAST = 'past';

    const STATUSES = [
        self::STATUS_CURRENT,
        self::STATUS_UPCOMING,
        self::STATUS_PAST,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($trip) {
            if (empty($trip->slug)) {
                $trip->slug = \Str::slug($trip->title);
            }
        });

        static::updating(function ($trip) {
            if ($trip->isDirty('title') && empty($trip->slug)) {
                $trip->slug = \Str::slug($trip->title);
            }
        });
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('sort_order');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Media::class)->where('type', 'photo')->orderBy('sort_order');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Media::class)->where('type', 'video')->orderBy('sort_order');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeCurrent($query)
    {
        return $query->byStatus(self::STATUS_CURRENT);
    }

    public function scopeUpcoming($query)
    {
        return $query->byStatus(self::STATUS_UPCOMING);
    }

    public function scopePast($query)
    {
        return $query->byStatus(self::STATUS_PAST);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
