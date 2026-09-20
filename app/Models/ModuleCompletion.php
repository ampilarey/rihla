<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person has read and how their last attempt at its quiz went.
 *
 * ## Keyed to the traveller, not the booking
 *
 * Somebody who travels twice keeps what they learned the first time.
 * Keying this to a booking would make them start again, which is both
 * wrong and insulting.
 *
 * ## The last attempt, not the best
 *
 * A pilgrim who got four of five and went back to read the fifth should
 * see four of five replaced, not a high score preserved. The number here
 * is a prompt to go back, not an achievement — nothing in this codebase
 * reads it as a permission, and no certificate is issued from it.
 */
class ModuleCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'traveller_id', 'learning_module_id', 'read_at',
        'questions_answered', 'questions_correct', 'quiz_taken_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'quiz_taken_at' => 'datetime',
        'questions_answered' => 'integer',
        'questions_correct' => 'integer',
    ];

    /** @return BelongsTo<Traveller, $this> */
    public function traveller(): BelongsTo
    {
        return $this->belongsTo(Traveller::class);
    }

    /** @return BelongsTo<LearningModule, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(LearningModule::class, 'learning_module_id');
    }

    public function hasBeenRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * How the last attempt went, in words rather than a percentage.
     *
     * "4 of 5" is something a pilgrim can act on — there is one thing to go
     * back to. "80%" is a grade, which is not what this is for.
     */
    public function quizLabel(): ?string
    {
        if ($this->quiz_taken_at === null || $this->questions_answered === null) {
            return null;
        }

        return $this->questions_correct.' of '.$this->questions_answered;
    }
}
