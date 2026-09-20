<?php

namespace Tests\Feature;

use App\Models\ArticleReference;
use App\Models\KnowledgeArticle;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public Knowledge Centre's reader-facing pages — §7.1.
 *
 * Separate from {@see KnowledgeCentreTest}, which covers the editorial
 * standard — the gate an article passes through before it may be
 * published. This covers what a reader can reach once it has.
 *
 * Phase 5.1 built the editorial standard and the admin behind it and
 * stopped there. The reader's page arrived with §9.6's assistant, which
 * must answer "with citations" — and a citation that cannot be opened and
 * checked is not a citation.
 *
 * What these hold down is the same thing the Ziyarah Guide's tests hold
 * down, because it is the same gate: **nothing unapproved is reachable by
 * anybody holding a URL.** A preview link for religious content is the
 * thing §6.4 exists to prevent.
 */
class KnowledgeCentrePagesTest extends TestCase
{
    use RefreshDatabase;

    private function published(string $title = 'Placeholder article'): KnowledgeArticle
    {
        $article = KnowledgeArticle::factory()->create([
            'title' => ['en' => $title],
            'summary' => ['en' => 'Placeholder summary for a test fixture.'],
            'body' => ['en' => 'Placeholder body for a test fixture.'],
        ]);

        $article->forceFill([
            'status' => KnowledgeArticle::PUBLISHED,
            'reviewed_by' => Person::factory()->create(['name' => 'Placeholder Scholar'])->getKey(),
            'reviewed_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ])->save();

        return $article->refresh();
    }

    // ── The gate ─────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function unpublishedStatuses(): array
    {
        return [
            'a draft' => [KnowledgeArticle::DRAFT],
            'one waiting on a scholar' => [KnowledgeArticle::IN_REVIEW],
            'one approved but not published' => [KnowledgeArticle::APPROVED],
            'one taken down' => [KnowledgeArticle::WITHDRAWN],
        ];
    }

    #[DataProvider('unpublishedStatuses')]
    public function test_nothing_unpublished_is_reachable_by_url(string $status): void
    {
        $article = KnowledgeArticle::factory()->create([
            'title' => ['en' => 'Placeholder article'],
            'status' => $status,
        ]);

        $this->get(route('knowledge.show', ['locale' => 'en', 'slug' => $article->slug]))
            ->assertNotFound();
    }

    public function test_an_unpublished_article_is_not_in_the_list(): void
    {
        KnowledgeArticle::factory()->create([
            'title' => ['en' => 'Draft placeholder article'],
            'status' => KnowledgeArticle::IN_REVIEW,
        ]);

        $this->get(route('knowledge.index', ['locale' => 'en']))
            ->assertSuccessful()
            ->assertDontSee('Draft placeholder article');
    }

    /**
     * An empty guide says why, and the why is the point.
     *
     * "Nothing here yet" with no cause reads as a broken site. The cause
     * is also the most honest pressure on the thing that is missing.
     */
    public function test_an_empty_centre_names_the_reviewer_nobody_has_appointed(): void
    {
        $this->get(route('knowledge.index', ['locale' => 'en']))
            ->assertSuccessful()
            ->assertSee('checked by a named scholar before it goes up');
    }

    // ── What a reader sees ───────────────────────────────────────────────

    public function test_a_published_article_is_listed_and_readable(): void
    {
        $article = $this->published('Placeholder on passports');

        $this->get(route('knowledge.index', ['locale' => 'en']))
            ->assertSuccessful()
            ->assertSee('Placeholder on passports');

        $this->get(route('knowledge.show', ['locale' => 'en', 'slug' => $article->slug]))
            ->assertSuccessful()
            ->assertSee('Placeholder on passports')
            ->assertSee('Placeholder body for a test fixture.');
    }

    /**
     * The scholar's name is on the page, not in a footer nobody reads.
     *
     * §7.1 asks for a named reviewer a reader can see, and the whole
     * apparatus behind the page is pointless if the reader cannot tell it
     * happened.
     */
    public function test_the_scholar_who_checked_it_is_named_on_the_page(): void
    {
        $article = $this->published();

        $this->get(route('knowledge.show', ['locale' => 'en', 'slug' => $article->slug]))
            ->assertSee('Placeholder Scholar');
    }

    /**
     * A cautionary narration stays on the page and is labelled.
     *
     * Removing it leaves a pilgrim hearing it elsewhere with no correction
     * (§7.1), which only works if the label is noticed.
     */
    public function test_a_weak_narration_is_shown_and_labelled_rather_than_hidden(): void
    {
        $article = $this->published();

        ArticleReference::create([
            'referenceable_type' => KnowledgeArticle::class,
            'referenceable_id' => $article->getKey(),
            'kind' => ArticleReference::HADITH,
            'citation' => 'Placeholder citation for a test fixture.',
            'grading' => ArticleReference::DAIF,
            'sort_order' => 0,
        ]);

        $this->get(route('knowledge.show', ['locale' => 'en', 'slug' => $article->slug]))
            ->assertSuccessful()
            ->assertSee('Placeholder citation for a test fixture.')
            ->assertSee('border-error/40', false);
    }

    /** Nothing religious is seeded, here as anywhere else. */
    public function test_no_article_is_seeded(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertSame(0, KnowledgeArticle::count());
    }
}
