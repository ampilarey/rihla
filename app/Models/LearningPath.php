<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

/**
 * A curated route through the modules — §7.3's learning paths.
 *
 * ## No editorial gate on the path itself
 *
 * Deliberately. A path asserts nothing religious: it is an ordering of
 * modules, each of which has already been through a scholar. Putting a
 * second review on the container would mean a scholar signing off a list,
 * which teaches them to sign without reading — the exact failure §6.4
 * exists to prevent. So a path is published by the office, and everything
 * in it was signed off by a named person first.
 *
 * ## The audience is the whole of what distinguishes one path from another
 *
 * §7.3 lists beginner, intermediate, advanced, women's, family and
 * children's. Nothing else about a path varies, so nothing else is stored.
 */
class LearningPath extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = ['slug', 'name', 'summary', 'audience', 'is_published', 'sort_order'];

    /** @var array<int, string> */
    public array $translatable = ['name', 'summary'];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    public const BEGINNER = 'beginner';

    public const INTERMEDIATE = 'intermediate';

    public const ADVANCED = 'advanced';

    public const WOMEN = 'women';

    public const FAMILY = 'family';

    public const CHILDREN = 'children';

    /** @var list<string> */
    public const AUDIENCES = [
        self::BEGINNER, self::INTERMEDIATE, self::ADVANCED,
        self::WOMEN, self::FAMILY, self::CHILDREN,
    ];

    /**
     * The pivot table is named, not derived.
     *
     * Laravel would build `learning_module_learning_path` from the two
     * class names in alphabetical order; the migration calls it
     * `learning_path_module`, which is how anybody reading the schema
     * expects it to read. Leaving it to the convention meant every read
     * worked and the first *write* failed on a missing table.
     *
     * @return BelongsToMany<LearningModule, $this>
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(LearningModule::class, 'learning_path_module')
            ->withPivot('sort_order')
            ->orderBy('learning_path_module.sort_order');
    }

    /**
     * The modules a reader can actually open.
     *
     * A path can hold a module still with a scholar; it simply does not
     * appear until it is signed off. The alternative — hiding the whole
     * path until every module is ready — means one unreviewed module
     * withholds ten finished ones.
     *
     * @return BelongsToMany<LearningModule, $this>
     */
    public function publishedModules(): BelongsToMany
    {
        return $this->modules()->live();
    }

    /** Whole words, never a key built by concatenation. */
    public function audienceLabel(): string
    {
        return match ($this->audience) {
            self::BEGINNER => 'First time',
            self::INTERMEDIATE => 'Been before',
            self::ADVANCED => 'In depth',
            self::WOMEN => 'For women',
            self::FAMILY => 'For families',
            self::CHILDREN => 'For children',
            default => 'Unknown',
        };
    }

    /**
     * @param  Builder<LearningPath>  $query
     * @return Builder<LearningPath>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
