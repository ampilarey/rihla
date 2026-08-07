<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhySection extends Model
{
    use HasFactory;

    protected $fillable = [
        'locale',
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

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(WhyFeature::class)->orderBy('sort_order');
    }
}
