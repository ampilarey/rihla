<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * One line of the {@see ReviewQueue}.
 *
 * A class rather than an array shape, for the reason
 * {@see StudyPlanItem} records: a `Collection`'s value template is
 * invariant, so an array shape inside one cannot be handed back from a
 * typed method.
 */
final class ReviewQueueItem
{
    public function __construct(
        public readonly string $kind,
        public readonly string $kindLabel,
        public readonly string $title,
        // `CarbonInterface`, not `Illuminate\Support\Carbon`: a model's
        // `updated_at` is typed as `Carbon\Carbon`, which is the parent of
        // Laravel's subclass rather than an instance of it.
        public readonly ?CarbonInterface $waitingSince,
        public readonly string $url,
        /** Null where the idea does not apply — a pilgrim's question has no sources yet. */
        public readonly ?int $sourceCount,
    ) {}

    /**
     * How long somebody has been waiting, as a duration.
     *
     * The number that matters here — "3 weeks" is a person who has been
     * ignored, where a date is arithmetic the reader has to do themselves.
     *
     * `DIFF_ABSOLUTE` drops the "ago", because the desk puts this inside a
     * sentence that already starts with "waiting". The first version read
     * "waiting 1 month ago", which a screenshot caught and no assertion
     * did. The questions table, where the same number stands on its own in
     * a column, keeps the "ago" and formats it there.
     */
    public function waitingFor(): string
    {
        return $this->waitingSince === null
            ? 'unknown'
            : $this->waitingSince->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);
    }

    /**
     * Whether this has been waiting long enough to be embarrassing.
     *
     * A fortnight. Nothing enforces it and nothing is blocked by it — it
     * only changes the colour, so a queue that is quietly growing looks
     * like one.
     */
    public function isStale(): bool
    {
        return $this->waitingSince !== null && $this->waitingSince->lt(now()->subDays(14));
    }
}
