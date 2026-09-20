<?php

namespace App\Services\Learning;

use App\Models\LearningModule;
use App\Models\ModuleCompletion;
use App\Models\QuizQuestion;
use App\Models\Traveller;
use Illuminate\Support\Collection;

/**
 * Recording what somebody has read and how their last quiz attempt went —
 * §7.3's progress tracking.
 *
 * ## Reading is marked by opening the page, and it is idempotent
 *
 * There is no "mark as complete" button. A button records that somebody
 * pressed a button; opening the page is the closest thing this system can
 * honestly observe to somebody having read the module, and it is what the
 * plan reports. Re-opening it does not reset anything.
 *
 * ## Grading is strict, and says so to the reader
 *
 * A question with several right answers is only right when the set chosen
 * matches the set marked correct. Partial credit on a question about how
 * to perform a rite would tell a pilgrim they were mostly right about
 * something they are about to do.
 */
final class Progress
{
    /**
     * Note that this person has read this module.
     *
     * Idempotent, and it never moves the date backwards or forwards: the
     * first read is the one recorded, because "when did they first learn
     * this" is the question the office asks and re-reading is not news.
     */
    public function markRead(Traveller $traveller, LearningModule $module): ModuleCompletion
    {
        $completion = ModuleCompletion::firstOrNew([
            'traveller_id' => $traveller->getKey(),
            'learning_module_id' => $module->getKey(),
        ]);

        $completion->read_at ??= now();
        $completion->save();

        return $completion;
    }

    /**
     * Grade an attempt and record it.
     *
     * `$answers` maps a question id to the option ids chosen for it. A
     * question the pilgrim skipped is simply absent, and counts as
     * unanswered rather than wrong — there is a difference between not
     * knowing and getting it wrong, and only one of them is worth showing
     * somebody.
     *
     * @param  array<int|string, array<int|string>>  $answers
     * @return array{answered: int, correct: int, results: Collection<int, QuizAnswer>}
     */
    public function recordQuiz(Traveller $traveller, LearningModule $module, array $answers): array
    {
        $questions = $module->quizQuestions()->with('options')->get();

        $results = $questions->map(function (QuizQuestion $question) use ($answers): QuizAnswer {
            $chosen = array_values(
                collect($answers[$question->getKey()] ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter()
                    ->unique()
                    ->sort()
                    ->all(),
            );

            $expected = array_values(
                $question->correctOptions()
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->sort()
                    ->all(),
            );

            return new QuizAnswer(
                question: $question,
                // Strict set equality. Partial credit on "how do I perform
                // this rite" would tell somebody they were mostly right
                // about something they are about to do.
                correct: $chosen !== [] && $chosen === $expected,
                chosen: $chosen,
            );
        });

        $answered = $results->filter(fn (QuizAnswer $answer): bool => $answer->wasAnswered())->count();
        $correct = $results->filter(fn (QuizAnswer $answer): bool => $answer->correct)->count();

        $completion = $this->markRead($traveller, $module);

        // The last attempt, not the best. Somebody who went back to read
        // the one they missed should see the new number.
        $completion->forceFill([
            'questions_answered' => $answered,
            'questions_correct' => $correct,
            'quiz_taken_at' => now(),
        ])->save();

        return ['answered' => $answered, 'correct' => $correct, 'results' => $results];
    }
}
