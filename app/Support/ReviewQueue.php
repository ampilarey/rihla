<?php

namespace App\Support;

use App\Models\KnowledgeArticle;
use App\Models\LearningModule;
use App\Models\ScholarQuestion;
use App\Models\ZiyarahLocation;
use Illuminate\Support\Collection;

/**
 * Everything waiting on a scholar, in one list — §6.4.
 *
 * ## Why this exists at all
 *
 * By the end of Phase 5 there are three resources a scholar reviews and a
 * question queue they answer, each behind its own navigation entry with its
 * own badge. A reviewer who has to check four screens to find out whether
 * anybody needs them checks none of them, and the whole editorial gate
 * quietly becomes a bottleneck nobody can see the length of.
 *
 * ## It reads, and does not act
 *
 * Signing off happens on the thing being signed off, where the sources and
 * the text are. A queue that could approve from a list would be a list of
 * titles somebody presses a button on, which is the review turning into a
 * formality — the exact failure §6.4 exists to prevent.
 *
 * ## The oldest first, and the age said out loud
 *
 * Not the newest, and not grouped by kind. The number that matters to
 * somebody looking at this is how long the person at the other end has been
 * waiting, and an article that has sat for three weeks is the one to open.
 */
final class ReviewQueue
{
    public const ARTICLE = 'article';

    public const LOCATION = 'location';

    public const MODULE = 'module';

    public const QUESTION = 'question';

    /**
     * Everything waiting, oldest first.
     *
     * @return Collection<int, ReviewQueueItem>
     */
    public static function build(): Collection
    {
        $items = new Collection;

        foreach (KnowledgeArticle::awaitingAScholar()->get() as $article) {
            $items->push(new ReviewQueueItem(
                kind: self::ARTICLE,
                kindLabel: 'Knowledge Centre article',
                title: (string) $article->title,
                waitingSince: $article->updated_at,
                url: route('filament.staff.resources.knowledge.knowledge-articles.edit', $article),
                sourceCount: $article->references()->count(),
            ));
        }

        foreach (ZiyarahLocation::awaitingAScholar()->get() as $location) {
            $items->push(new ReviewQueueItem(
                kind: self::LOCATION,
                kindLabel: 'Ziyarah location',
                title: (string) $location->name,
                waitingSince: $location->updated_at,
                url: route('filament.staff.resources.ziyarah.ziyarah-locations.edit', $location),
                sourceCount: $location->references()->count(),
            ));
        }

        foreach (LearningModule::awaitingAScholar()->get() as $module) {
            $items->push(new ReviewQueueItem(
                kind: self::MODULE,
                kindLabel: 'Learning module',
                title: (string) $module->title,
                waitingSince: $module->updated_at,
                url: route('filament.staff.resources.learning.learning-modules.edit', $module),
                sourceCount: $module->references()->count(),
            ));
        }

        foreach (ScholarQuestion::waiting()->orderBy('created_at')->get() as $question) {
            $items->push(new ReviewQueueItem(
                kind: self::QUESTION,
                kindLabel: 'Question from a pilgrim',
                // Not the whole thing. Somebody's private question does not
                // belong in full on a list screen anybody with the
                // permission can leave open; the link opens it.
                title: str($question->body)->limit(90)->toString(),
                waitingSince: $question->created_at,
                url: route('filament.staff.resources.questions.scholar-questions.index'),
                sourceCount: null,
            ));
        }

        return $items
            ->sortBy(fn (ReviewQueueItem $item): int => $item->waitingSince?->getTimestamp() ?? 0)
            ->values();
    }

    /** What the navigation badge counts. */
    public static function count(): int
    {
        return KnowledgeArticle::awaitingAScholar()->count()
            + ZiyarahLocation::awaitingAScholar()->count()
            + LearningModule::awaitingAScholar()->count()
            + ScholarQuestion::waiting()->count();
    }
}
