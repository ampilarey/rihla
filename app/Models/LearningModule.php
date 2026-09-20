<?php

namespace App\Models;

use App\Models\Concerns\EditorialGate;
use App\Support\StudyPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * One lesson in the Learning Academy — §7.3.
 *
 * ## It is a knowledge article that is due on a date
 *
 * Same editorial gate as §7.1 and §7.2, through {@see EditorialGate}: a
 * module teaching somebody how to perform tawaf is a religious claim in
 * exactly the way a Knowledge Centre article is, and the reader is about to
 * act on it rather than merely read it.
 *
 * What it adds is `days_before_departure`, which is the whole of what
 * personalises §7.3's "personalised study plan": everything else is
 * arithmetic on a date {@see StudyPlan} already has.
 *
 * ## The quiz is not a gate
 *
 * Nobody fails their way out of Umrah. {@see quizQuestions()} exists so a
 * pilgrim can find out what they have misunderstood while there is still
 * time to ask, and the score is a prompt to go back rather than a mark.
 * Nothing in this codebase reads it as a permission.
 */
class LearningModule extends Model
{
    use EditorialGate;
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'slug', 'title', 'summary', 'body', 'minutes',
        'days_before_departure', 'ziyarah_location_id',
    ];

    /** @var array<int, string> */
    public array $translatable = ['title', 'summary', 'body'];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'minutes' => 'integer',
        'days_before_departure' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    protected function editorialSubject(): string
    {
        return 'module';
    }

    /** @return BelongsToMany<LearningPath, $this> */
    public function paths(): BelongsToMany
    {
        return $this->belongsToMany(LearningPath::class, 'learning_path_module')->withPivot('sort_order');
    }

    /** @return HasMany<QuizQuestion, $this> */
    public function quizQuestions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort_order');
    }

    /** @return HasMany<ModuleCompletion, $this> */
    public function completions(): HasMany
    {
        return $this->hasMany(ModuleCompletion::class);
    }

    /**
     * The place this module is about, if it is about a place.
     *
     * §7.3's itinerary tie-in: "if the trip visits Uhud, surface Uhud's
     * history the week before."
     *
     * @return BelongsTo<ZiyarahLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ZiyarahLocation::class, 'ziyarah_location_id');
    }

    public function hasQuiz(): bool
    {
        return $this->quizQuestions()->exists();
    }

    /**
     * Roughly how long, in the words a reader uses.
     *
     * "Eleven modules" means nothing to somebody deciding whether to start
     * one now; "about 6 minutes" means everything.
     */
    public function lengthLabel(): ?string
    {
        if ($this->minutes === null || $this->minutes < 1) {
            return null;
        }

        return $this->minutes === 1 ? 'about a minute' : 'about '.$this->minutes.' minutes';
    }

    /**
     * @param  Builder<LearningModule>  $query
     * @return Builder<LearningModule>
     */
    public function scopeDueBy(Builder $query, int $daysBefore): Builder
    {
        return $query->where('days_before_departure', '>=', $daysBefore);
    }
}
