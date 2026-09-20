<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Translatable\HasTranslations;

/**
 * Something pilgrims are commonly told about a place, and what is actually
 * the case — §7.2's "common misconceptions".
 *
 * ## Two fields, not one paragraph
 *
 * The contrast is the thing that lands. A paragraph that works round to the
 * correction is skimmed past; "what people say" beside "what is actually
 * the case" is read, and is the shape somebody can repeat to the person who
 * told them.
 *
 * ## Each one carries its own sources
 *
 * Through the same polymorphic {@see ArticleReference} table as an article,
 * so the grading rule — no ungraded hadith, no graded verse — applies here
 * without a second copy of it. {@see ZiyarahLocation::whyNotApprovable()}
 * refuses to approve a location while any correction on it is unsourced.
 */
class LocationMisconception extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = ['ziyarah_location_id', 'belief', 'correction', 'sort_order'];

    /** @var array<int, string> */
    public array $translatable = ['belief', 'correction'];

    protected $casts = ['sort_order' => 'integer'];

    /** @return BelongsTo<ZiyarahLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ZiyarahLocation::class, 'ziyarah_location_id');
    }

    /** @return MorphMany<ArticleReference, $this> */
    public function references(): MorphMany
    {
        return $this->morphMany(ArticleReference::class, 'referenceable')->orderBy('sort_order');
    }
}
