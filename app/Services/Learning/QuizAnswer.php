<?php

namespace App\Services\Learning;

use App\Models\QuizQuestion;
use App\Support\StudyPlanItem;

/**
 * How one question went on one attempt.
 *
 * A class rather than an array shape, for the reason
 * {@see StudyPlanItem} gives: a `Collection`'s value template
 * is invariant, so an array shape inside one cannot be handed back from a
 * typed method without repeating itself and still failing.
 */
final class QuizAnswer
{
    /** @param list<int> $chosen */
    public function __construct(
        public readonly QuizQuestion $question,
        public readonly bool $correct,
        public readonly array $chosen,
    ) {}

    /**
     * Not knowing and getting it wrong are different, and only one of them
     * is worth showing somebody.
     */
    public function wasAnswered(): bool
    {
        return $this->chosen !== [];
    }
}
