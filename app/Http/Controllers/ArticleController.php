<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\View\View;

/**
 * The blog: the questions pilgrims ask before they book.
 *
 * A plain resource rather than the page builder the source specification
 * proposes — see §4.4. What Rihla needs is somewhere to answer "what do I
 * pack", "how does the visa work", "what is Ramadan in Makkah like". Those
 * are articles.
 */
class ArticleController extends Controller
{
    public function index(): View
    {
        return view('articles.index', [
            'articles' => Article::published()
                ->with('author')
                ->orderByDesc('published_at')
                ->paginate(10),
        ]);
    }

    /**
     * No `$locale` parameter: SetLocale consumes it before the controller
     * runs, as it does for every other localised route.
     */
    public function show(string $slug): View
    {
        $article = Article::published()->with('author')->where('slug', $slug)->firstOrFail();

        return view('articles.show', [
            'article' => $article,
            'related' => Article::published()
                ->whereKeyNot($article->getKey())
                ->orderByDesc('published_at')
                ->take(3)
                ->get(),
        ]);
    }
}
