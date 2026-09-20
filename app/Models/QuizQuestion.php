<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Spatie\Translatable\HasTranslations;

/**
 * One question in a module's quiz — §7.3.
 *
 * ## A question carries sources, like everything else that makes a claim
 *
 * Through the same polymorphic {@see ArticleReference} table as an article,
 * a Ziyarah location and a misconception. A question that tells a pilgrim
 * they were *wrong* about a rite needs its source more than an article
 * does, not less: the article informs, the quiz corrects, and a correction
 * with nothing behind it is somebody's opinion delivered as a verdict.
 *
 * ## The explanation is the part that teaches
 *
 * Shown whether the answer was right or wrong. A mark on its own tells a
 * pilgrim they have a problem and not what it is.
 */
class QuizQuestion extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = ['learning_module_id', 'prompt', 'explanation', 'sort_order'];

    /** @var array<int, string> */
    public array $translatable = ['prompt', 'explanation'];

    protected $casts = ['sort_order' => 'integer'];

    /** @return BelongsTo<LearningModule, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(LearningModule::class, 'learning_module_id');
    }

    /** @return HasMany<QuizOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class)->orderBy('sort_order');
    }

    /** @return MorphMany<ArticleReference, $this> */
    public function references(): MorphMany
    {
        return $this->morphMany(ArticleReference::class, 'referenceable')->orderBy('sort_order');
    }

    /**
     * The answers this question counts as right.
     *
     * A collection rather than a single option: several of §7.3's topics
     * genuinely have more than one correct answer, and a question shape
     * that cannot express that pushes the author into writing a false
     * single-answer version of a real question.
     *
     * @return Collection<int, QuizOption>
     */
    public function correctOptions(): Collection
    {
        return $this->options->where('is_correct', true)->values();
    }

    /**
     * Whether this question could be asked of anybody yet, and if not, why.
     *
     * A question with no right answer marks every pilgrim wrong; one where
     * every answer is right teaches nothing. Both are caught here rather
     * than discovered by a pilgrim mid-quiz.
     */
    public function whyNotUsable(): ?string
    {
        $options = $this->options()->count();

        if ($options < 2) {
            return 'This question needs at least two answers to choose between.';
        }

        $correct = $this->options()->where('is_correct', true)->count();

        if ($correct === 0) {
            return 'No answer here is marked correct, so everybody who answers it would be told they were wrong.';
        }

        if ($correct === $options) {
            return 'Every answer here is marked correct, so the question teaches nothing.';
        }

        if ($this->references()->count() === 0) {
            return 'This question has no source. A question that tells a pilgrim they were wrong about a rite needs one more than an article does, not less.';
        }

        return null;
    }
}
