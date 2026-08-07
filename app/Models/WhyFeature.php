<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhyFeature extends Model
{
    use HasFactory;

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

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(WhySection::class, 'why_section_id');
    }
}
