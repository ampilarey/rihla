<?php

namespace App\Services\Assistant;

use App\Models\KnowledgeArticle;
use App\Models\LearningModule;
use App\Models\ZiyarahLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The only thing the pilgrim assistant may read — §9.6.
 *
 * ## Approved content, and a query rather than an instruction
 *
 * Every retrieval here goes through `live()`, the editorial gate's scope:
 * approved by a named scholar and published. A draft, an article in
 * review, and a withdrawn one are all invisible to this class, and no
 * prompt wording can reach them — which is the point. "Answer only from
 * approved content" written into a system prompt is a request; written as
 * the query, it is a fact.
 *
 * ## Keyword matching, and why that is honest here
 *
 * There is no vector store on cPanel shared hosting and no embedding
 * budget (ADR 0002). This matches on the words of the question against the
 * title and body of approved content, which is crude — and crude in the
 * *safe* direction: it finds less than a good retriever would, and finding
 * less means referring more questions to a human. The failure mode of a
 * poor retriever here is an unanswered question, not a wrong ruling.
 *
 * When Rihla's corpus is large enough for that to be the wrong trade, the
 * replacement goes behind this class and nothing else changes.
 */
final class Corpus
{
    /**
     * Words too common to narrow anything, dropped before matching.
     *
     * Deliberately short and English-only. It is a performance measure,
     * not a language model: a word wrongly kept costs a little noise, and
     * a word wrongly dropped could lose the only match.
     *
     * @var list<string>
     */
    private const NOISE = [
        'the', 'and', 'for', 'are', 'can', 'what', 'when', 'where', 'how', 'why',
        'is', 'do', 'does', 'did', 'was', 'were', 'have', 'has', 'with', 'from',
        'that', 'this', 'there', 'about', 'into', 'you', 'your', 'our', 'my',
        'should', 'would', 'could', 'will', 'shall', 'may', 'must', 'not',
    ];

    /**
     * Approved passages that share words with the question.
     *
     * @return Collection<int, Source>
     */
    public static function matching(string $question): Collection
    {
        $terms = self::terms($question);

        if ($terms === []) {
            return collect();
        }

        $limit = max(1, (int) config('assistant.max_sources', 4));

        return collect()
            ->merge(self::fromKnowledge($terms))
            ->merge(self::fromZiyarah($terms))
            ->merge(self::fromLearning($terms))
            ->sortByDesc(fn (array $hit): int => $hit['score'])
            ->take($limit)
            ->map(fn (array $hit): Source => $hit['source'])
            ->values();
    }

    /**
     * The words worth matching on.
     *
     * @return list<string>
     */
    public static function terms(string $question): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($question)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) >= 3 && ! in_array($word, self::NOISE, true),
        )));
    }

    /**
     * The locale is passed explicitly to every route() here.
     *
     * The public routes are locale-prefixed and the middleware sets the
     * URL default — but this class also runs from a console command and
     * from a queued context where no request has been through that
     * middleware, and there the default is absent and route() throws.
     *
     * @param  list<string>  $terms
     * @return list<array{score: int, source: Source}>
     */
    private static function fromKnowledge(array $terms): array
    {
        $hits = [];

        foreach (KnowledgeArticle::query()->live()->get() as $article) {
            $haystack = Str::lower(implode(' ', [
                (string) $article->title,
                (string) $article->summary,
                (string) $article->body,
            ]));

            $score = self::score($haystack, $terms);

            if ($score < 1) {
                continue;
            }

            $hits[] = ['score' => $score, 'source' => new Source(
                kind: 'knowledge',
                kindLabel: __('messages.Knowledge Centre'),
                title: (string) $article->title,
                url: route('knowledge.show', ['locale' => app()->getLocale(), 'slug' => $article->slug]),
                excerpt: self::excerpt((string) $article->summary."\n\n".(string) $article->body),
            )];
        }

        return $hits;
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{score: int, source: Source}>
     */
    private static function fromZiyarah(array $terms): array
    {
        $hits = [];

        foreach (ZiyarahLocation::query()->live()->get() as $location) {
            $haystack = Str::lower(implode(' ', [
                (string) $location->name,
                (string) $location->summary,
                (string) $location->history,
                (string) $location->significance,
                (string) $location->etiquette,
            ]));

            $score = self::score($haystack, $terms);

            if ($score < 1) {
                continue;
            }

            $hits[] = ['score' => $score, 'source' => new Source(
                kind: 'ziyarah',
                kindLabel: __('messages.Ziyarah Guide'),
                title: (string) $location->name,
                url: route('ziyarah.show', ['locale' => app()->getLocale(), 'slug' => $location->slug]),
                excerpt: self::excerpt(implode("\n\n", array_filter([
                    (string) $location->summary,
                    (string) $location->significance,
                    (string) $location->etiquette,
                ]))),
            )];
        }

        return $hits;
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{score: int, source: Source}>
     */
    private static function fromLearning(array $terms): array
    {
        $hits = [];

        foreach (LearningModule::query()->live()->get() as $module) {
            $haystack = Str::lower(implode(' ', [
                (string) $module->title,
                (string) $module->summary,
                (string) $module->body,
            ]));

            $score = self::score($haystack, $terms);

            if ($score < 1) {
                continue;
            }

            $hits[] = ['score' => $score, 'source' => new Source(
                kind: 'learning',
                kindLabel: __('messages.Learning Academy'),
                title: (string) $module->title,
                url: route('learning.show', ['locale' => app()->getLocale(), 'slug' => $module->slug]),
                excerpt: self::excerpt((string) $module->summary."\n\n".(string) $module->body),
            )];
        }

        return $hits;
    }

    /**
     * How many of the question's words this passage uses.
     *
     * Distinct words, not occurrences: a page that says "passport" forty
     * times is not forty times more relevant than one that also mentions
     * the visa and the permit.
     *
     * @param  list<string>  $terms
     */
    private static function score(string $haystack, array $terms): int
    {
        $found = 0;

        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $found++;
            }
        }

        return $found;
    }

    private static function excerpt(string $text): string
    {
        return Str::limit(trim(strip_tags($text)), max(200, (int) config('assistant.excerpt_characters', 1200)), '…');
    }
}
