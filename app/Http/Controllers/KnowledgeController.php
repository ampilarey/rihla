<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeArticle;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The public Knowledge Centre — §7.1.
 *
 * ## Why this exists only now
 *
 * Phase 5.1 built the editorial standard and the admin behind it, and
 * stopped there because no article could be published without a named
 * reviewer and none had been named. The reader's page was left for later.
 *
 * Later arrived with §9.6's pilgrim assistant, which must answer "with
 * citations" — and a citation that cannot be opened and checked is not a
 * citation. The assistant cannot honestly cite the Knowledge Centre while
 * the Knowledge Centre has no page a pilgrim can read, so this is part of
 * that work rather than a separate feature.
 *
 * ## Only what a scholar signed off reaches this controller
 *
 * `live()` is the only way an article gets here, so a draft, one waiting on
 * review and a withdrawn one are all 404 rather than "not published yet".
 * There is no preview link, for the reason {@see ZiyarahController} gives:
 * a URL that shows unreviewed religious content to whoever holds it is the
 * thing §6.4 exists to prevent.
 */
class KnowledgeController extends Controller
{
    public function index(): View
    {
        $articles = KnowledgeArticle::live()
            ->orderBy('category')
            ->orderBy('id')
            ->get();

        return view('knowledge.index', [
            'byCategory' => $this->groupByCategory($articles),
            'total' => $articles->count(),
        ]);
    }

    /**
     * No `$locale` parameter, even though the route carries one: SetLocale
     * consumes it before the controller runs.
     */
    public function show(string $slug): View
    {
        $article = KnowledgeArticle::live()
            ->where('slug', $slug)
            ->with(['reviewer', 'references'])
            ->firstOrFail();

        $related = KnowledgeArticle::live()
            ->where('category', $article->category)
            ->whereKeyNot($article->getKey())
            ->orderBy('id')
            ->limit(6)
            ->get();

        return view('knowledge.show', [
            'article' => $article,
            'related' => $related,
        ]);
    }

    /**
     * @param  Collection<int, KnowledgeArticle>  $articles
     * @return Collection<string, Collection<int, KnowledgeArticle>>
     */
    private function groupByCategory(Collection $articles): Collection
    {
        return $articles->groupBy(fn (KnowledgeArticle $article): string => (string) $article->category);
    }
}
