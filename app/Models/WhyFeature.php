<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function section(): BelongsTo
    {
        return $this->belongsTo(WhySection::class, 'why_section_id');
    }
}
