<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HeroBanner extends Model
{
    use HasFactory;

    protected $fillable = [
        'locale',
        'title',
        'subtitle',
        'primary_cta_text',
        'primary_cta_url',
        'secondary_cta_text',
        'secondary_cta_url',
        'image_path',
        'overlay_opacity',
        'heading_color',
        'heading_size',
        'heading_weight',
        'subheading_color',
        'subheading_size',
        'subheading_weight',
        'primary_cta_bg_color',
        'primary_cta_text_color',
        'primary_cta_size',
        'primary_cta_radius',
        'secondary_cta_bg_color',
        'secondary_cta_text_color',
        'secondary_cta_size',
        'secondary_cta_radius',
        'sort_order',
        'is_active',
        'start_at',
        'end_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'overlay_opacity' => 'integer',
        'sort_order' => 'integer',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    /**
     * Scope for published banners
     */
    /** @param Builder<HeroBanner> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope for active banners (published and within window)
     */
    /** @param Builder<HeroBanner> $query */
    public function scopeActive(Builder $query): void
    {
        $query->published()->withinWindow();
    }

    /**
     * Scope for banners in a specific locale
     */
    /** @param Builder<HeroBanner> $query */
    public function scopeForLocale(Builder $query, string $locale): void
    {
        $query->where('locale', $locale);
    }

    /**
     * Scope for ordered banners
     */
    /** @param Builder<HeroBanner> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order', 'asc');
    }

    /**
     * Scope for banners within their scheduled window
     */
    /** @param Builder<HeroBanner> $query */
    public function scopeWithinWindow(Builder $query): void
    {
        $now = Carbon::now();
        $query->where(function (Builder $q) use ($now) {
            $q->whereNull('start_at')
                ->orWhere('start_at', '<=', $now);
        })->where(function (Builder $q) use ($now) {
            $q->whereNull('end_at')
                ->orWhere('end_at', '>=', $now);
        });
    }

    /**
     * Get the image URL attribute
     */
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return asset('storage/'.$this->image_path);
    }

    /**
     * Get the responsive image URLs
     */
    public function getResponsiveImageUrlsAttribute(): array
    {
        if (! $this->image_path) {
            return [];
        }

        $basePath = str_replace('.jpg', '', $this->image_path);
        $basePath = str_replace('.png', '', $basePath);
        $basePath = str_replace('.webp', '', $basePath);

        return [
            'large' => asset('storage/'.$basePath.'_1920w.webp'),
            'medium' => asset('storage/'.$basePath.'_1280w.webp'),
            'small' => asset('storage/'.$basePath.'_768w.webp'),
        ];
    }

    /**
     * Check if banner is currently visible
     */
    public function isCurrentlyVisible(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = Carbon::now();

        if ($this->start_at && $now->lt($this->start_at)) {
            return false;
        }

        if ($this->end_at && $now->gt($this->end_at)) {
            return false;
        }

        return true;
    }

    /**
     * Get the effective CTA text (with fallback)
     */
    public function getEffectiveCtaTextAttribute(): ?string
    {
        return $this->primary_cta_text ?: __('Explore Trips');
    }

    /**
     * Get the effective CTA URL (with fallback)
     */
    public function getEffectiveCtaUrlAttribute(): string
    {
        return $this->primary_cta_url ?: route('trips.index');
    }
}
