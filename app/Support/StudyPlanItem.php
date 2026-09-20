<?php

namespace App\Support;

use App\Models\LearningModule;
use App\Models\ModuleCompletion;
use Carbon\CarbonImmutable;

/**
 * One line of a {@see StudyPlan}: a module, when it is due, and where this
 * pilgrim has got to with it.
 *
 * A class rather than an array shape. The shape version typed cleanly on
 * its own and not through a `Collection`, whose value template is
 * deliberately invariant — so every method handing one back had to repeat
 * the whole shape and still did not satisfy it. A named type also means the
 * Blade templates read `$entry->due_on` instead of `$entry['due_on']`,
 * which is the difference between a typo being a blank on the page and a
 * typo being an error.
 */
final class StudyPlanItem
{
    public function __construct(
        public readonly LearningModule $module,
        public readonly CarbonImmutable $due_on,
        public readonly string $state,
        /** Negative when the date has passed. */
        public readonly int $days_until_due,
        public readonly ?ModuleCompletion $completion,
    ) {}

    public function isDone(): bool
    {
        return $this->state === StudyPlan::DONE;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->days_until_due < 0;
    }

    public function isDueToday(): bool
    {
        return ! $this->isDone() && $this->days_until_due === 0;
    }
}
