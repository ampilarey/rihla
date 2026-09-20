<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Person;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The blog.
 *
 * A plain resource rather than the visual page builder the source
 * specification proposes (§4.4): what Rihla needs is somewhere to answer the
 * questions pilgrims ask before they book, and those are articles.
 */
class ArticleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_published_article_is_listed_and_readable(): void
    {
        $article = Article::factory()->create([
            'title' => ['en' => 'What to pack for Umrah'],
            'excerpt' => ['en' => 'Ihram, and rather less else than you think.'],
        ]);

        $this->get('/en/articles')->assertOk()->assertSee('What to pack for Umrah');
        $this->get('/en/articles/'.$article->slug)->assertOk()->assertSee('Ihram, and rather less');
    }

    /**
     * Three states, not two. A draft has no date and a scheduled article has
     * a future one; both are invisible, for different reasons, and one scope
     * covers the listing, the page and the sitemap so they cannot disagree.
     */
    public function test_a_draft_is_invisible(): void
    {
        $article = Article::factory()->draft()->create(['title' => ['en' => 'Still writing']]);

        $this->get('/en/articles')->assertOk()->assertDontSee('Still writing');
        $this->get('/en/articles/'.$article->slug)->assertNotFound();
    }

    public function test_a_scheduled_article_waits_for_its_date(): void
    {
        $article = Article::factory()->scheduled()->create(['title' => ['en' => 'Out on Friday']]);

        $this->get('/en/articles')->assertOk()->assertDontSee('Out on Friday');
        $this->get('/en/articles/'.$article->slug)->assertNotFound();

        $this->travelTo(now()->addWeeks(2));

        $this->get('/en/articles/'.$article->slug)->assertOk()->assertSee('Out on Friday');
    }

    public function test_an_author_is_shown_when_there_is_one(): void
    {
        $author = Person::factory()->create(['name' => 'Sheikh Ibrahim']);
        $article = Article::factory()->create(['author_id' => $author->id]);

        $this->get('/en/articles/'.$article->slug)->assertOk()->assertSee('Sheikh Ibrahim');
    }

    /**
     * The body is typed into a textarea by staff, not pasted HTML. Rendering
     * it raw would make the admin panel an injection route straight past the
     * Content-Security-Policy.
     */
    public function test_the_body_is_escaped(): void
    {
        $article = Article::factory()->create([
            'body' => ['en' => '<script>alert("xss")</script> and a <b>bold</b> claim'],
        ]);

        $html = $this->get('/en/articles/'.$article->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', (string) $html);
        $this->assertStringNotContainsString('<b>bold</b>', (string) $html);
        $this->assertStringContainsString('&lt;script&gt;', (string) $html);
    }

    public function test_line_breaks_survive(): void
    {
        $article = Article::factory()->create(['body' => ['en' => "First line\nSecond line"]]);

        $this->get('/en/articles/'.$article->slug)->assertOk()->assertSee('<br />', false);
    }

    public function test_an_untranslated_article_falls_back_rather_than_rendering_blank(): void
    {
        $article = Article::factory()->create(['title' => ['en' => 'English only']]);

        $this->get('/dv/articles/'.$article->slug)->assertOk()->assertSee('English only');
    }

    // ── SEO ──────────────────────────────────────────────────────────────

    public function test_an_article_publishes_itself_as_a_blog_posting(): void
    {
        $author = Person::factory()->create(['name' => 'Sheikh Ibrahim']);
        $article = Article::factory()->create([
            'title' => ['en' => 'How the Umrah visa works'],
            'author_id' => $author->id,
        ]);

        $html = $this->get('/en/articles/'.$article->slug)->assertOk()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', (string) $html, $matches);

        $schema = collect($matches[1])
            ->map(fn (string $json): array => json_decode(trim($json), true) ?: [])
            ->firstWhere('@type', 'BlogPosting');

        $this->assertNotNull($schema, 'No BlogPosting was published.');
        $this->assertSame('How the Umrah visa works', $schema['headline']);
        $this->assertSame('Sheikh Ibrahim', $schema['author']['name']);
        $this->assertNotEmpty($schema['datePublished']);
    }

    /**
     * Attributing an unattributed article to the organisation would be a
     * claim the page does not make — and on religious guidance the author is
     * not incidental.
     */
    public function test_an_unattributed_article_claims_no_author(): void
    {
        $article = Article::factory()->create(['author_id' => null]);

        $html = $this->get('/en/articles/'.$article->slug)->assertOk()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', (string) $html, $matches);

        $schema = collect($matches[1])
            ->map(fn (string $json): array => json_decode(trim($json), true) ?: [])
            ->firstWhere('@type', 'BlogPosting');

        $this->assertArrayNotHasKey('author', $schema);
    }

    public function test_articles_are_in_the_sitemap_and_drafts_are_not(): void
    {
        $published = Article::factory()->create();
        $draft = Article::factory()->draft()->create();

        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/en/articles/'.$published->slug, $xml);
        $this->assertStringContainsString('/dv/articles', $xml);
        $this->assertStringNotContainsString($draft->slug, $xml);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function test_a_content_manager_can_manage_articles(): void
    {
        $editor = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->actingAs($editor)->get('/staff/articles')->assertOk();
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $leader = User::factory()->create()->assignRole(Access::TOUR_LEADER);

        $this->actingAs($leader)->get('/staff/articles')->assertForbidden();
    }
}
