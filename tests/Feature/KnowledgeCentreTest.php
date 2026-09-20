<?php

namespace Tests\Feature;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\ArticleReference;
use App\Models\KnowledgeArticle;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Knowledge Centre's editorial standard — §7.1.
 *
 * §7.1 asks for the standard to be "required fields, not a style guide
 * people forget". These tests are the difference between those two things:
 * every one of them tries to get religious content onto the site without
 * meeting the standard, and fails.
 *
 * The failure worth preventing is not a broken page. It is a page that
 * looks authoritative, carries no source, and is read by somebody about to
 * perform an act of worship.
 */
class KnowledgeCentreTest extends TestCase
{
    use RefreshDatabase;

    private function article(): KnowledgeArticle
    {
        return KnowledgeArticle::factory()->create();
    }

    private function scholar(): Person
    {
        return Person::factory()->create(['name' => 'Sheikh Placeholder', 'role' => 'scholar']);
    }

    // ── Every claim carries a source ─────────────────────────────────────

    /** The requirement §7.1 states first, and the one easiest to skip. */
    public function test_an_article_with_no_source_cannot_be_approved(): void
    {
        $article = $this->article();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('No source yet');

        $article->approve($this->scholar());
    }

    public function test_an_article_with_a_source_can_be_approved(): void
    {
        $article = $this->article();
        ArticleReference::factory()->create(['referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey()]);

        $article->fresh()->approve($this->scholar(), 'Checked the citation.');

        $article = $article->fresh();

        $this->assertSame(KnowledgeArticle::APPROVED, $article->status);
        $this->assertNotNull($article->reviewed_at);
        $this->assertSame('Sheikh Placeholder', $article->reviewer->name);
    }

    public function test_the_screen_can_say_why_it_is_not_approvable_yet(): void
    {
        $article = $this->article();

        $this->assertStringContainsString('No source', (string) $article->whyNotApprovable());

        ArticleReference::factory()->create(['referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey()]);

        $this->assertNull($article->fresh()->whyNotApprovable());
    }

    // ── A hadith without a grading cannot exist ──────────────────────────

    /**
     * Enforced on the model, not in a form.
     *
     * A validation rule on one Filament form is a style guide with extra
     * steps: it is bypassed by a seeder, a console command, or the next
     * screen somebody writes. This is the check that cannot be walked past.
     */
    public function test_a_hadith_reference_without_a_grading_is_refused(): void
    {
        $article = $this->article();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry a grading');

        ArticleReference::create([
            'referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey(),
            'kind' => ArticleReference::HADITH,
            'citation' => 'Placeholder collection 1',
        ]);
    }

    public function test_a_quran_reference_needs_no_grading(): void
    {
        $article = $this->article();

        $reference = ArticleReference::create([
            'referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey(),
            'kind' => ArticleReference::QURAN,
            'citation' => 'Placeholder citation',
        ]);

        $this->assertNull($reference->grading);
    }

    /**
     * A grading on a verse is a category error a reader would take
     * seriously, so it is stripped rather than stored.
     */
    public function test_a_grading_put_on_a_quran_reference_is_dropped(): void
    {
        $article = $this->article();

        $reference = ArticleReference::create([
            'referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey(),
            'kind' => ArticleReference::QURAN,
            'citation' => 'Placeholder citation',
            'grading' => ArticleReference::SAHIH,
        ]);

        $this->assertNull($reference->fresh()->grading);
    }

    // ── Weak and disputed narrations are labelled, not deleted ───────────

    /**
     * §7.1: "disputed/weak narrations are labelled as such."
     *
     * Keeping them is the point. Naming a weak narration as weak is what
     * stops a pilgrim repeating it; deleting it leaves them hearing it
     * somewhere else with no correction.
     */
    public function test_a_weak_narration_is_kept_and_flagged(): void
    {
        $article = $this->article();

        ArticleReference::factory()->hadith(ArticleReference::DAIF)->create([
            'referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey(),
            'note' => 'Included as a caution — pilgrims are often told this.',
        ]);

        $cautions = $article->fresh()->cautions();

        $this->assertCount(1, $cautions);
        $this->assertStringContainsString('weak', $cautions->first()->gradingLabel());
    }

    public function test_every_cautionary_grading_is_flagged(): void
    {
        foreach (ArticleReference::CAUTIONARY as $grading) {
            $reference = new ArticleReference(['kind' => ArticleReference::HADITH, 'grading' => $grading]);

            $this->assertTrue($reference->isCautionary(), "[{$grading}] is not flagged as cautionary.");
        }
    }

    public function test_an_authentic_narration_is_not_flagged_as_a_caution(): void
    {
        foreach ([ArticleReference::SAHIH, ArticleReference::HASAN] as $grading) {
            $reference = new ArticleReference(['kind' => ArticleReference::HADITH, 'grading' => $grading]);

            $this->assertFalse($reference->isCautionary(), "[{$grading}] is wrongly flagged.");
        }
    }

    /**
     * The label says what the grading means, not only what it is called.
     *
     * Somebody who does not know what da'if is should still understand not
     * to repeat the narration.
     */
    public function test_every_grading_explains_itself(): void
    {
        foreach (ArticleReference::GRADINGS as $grading) {
            $label = (new ArticleReference(['kind' => ArticleReference::HADITH, 'grading' => $grading]))->gradingLabel();

            $this->assertNotSame('', $label, "[{$grading}] has no label.");
            // A bare transliteration is not an explanation.
            $this->assertMatchesRegularExpression('/—|among/', $label, "[{$grading}] names itself without explaining.");
        }
    }

    // ── Review before publish is non-negotiable ──────────────────────────

    /** §6.4, in one test. */
    public function test_an_unapproved_article_cannot_be_published(): void
    {
        $article = $this->article();
        ArticleReference::factory()->create(['referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey()]);

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('non-negotiable');

        $article->fresh()->publish();
    }

    public function test_an_article_in_review_cannot_be_published(): void
    {
        $article = $this->article();
        ArticleReference::factory()->create(['referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey()]);
        $article->sendForReview();

        $this->expectException(EditorialStandardNotMet::class);

        $article->fresh()->publish();
    }

    public function test_an_approved_article_publishes_and_names_its_scholar(): void
    {
        $article = $this->article();
        ArticleReference::factory()->create(['referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey()]);

        $article->fresh()->approve($this->scholar());
        $article->fresh()->publish();

        $article = $article->fresh();

        $this->assertTrue($article->isLive());
        $this->assertSame('Sheikh Placeholder', $article->reviewer->name);
    }

    /**
     * Approving and publishing are separate acts.
     *
     * A scholar signing something off is a judgement about content;
     * publishing is a decision about timing. One button doing both makes
     * the scholar the publisher, which nobody asked for.
     */
    public function test_approval_alone_does_not_put_it_on_the_site(): void
    {
        $article = $this->article();
        ArticleReference::factory()->create(['referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey()]);

        $article->fresh()->approve($this->scholar());

        $this->assertFalse($article->fresh()->isLive());
        $this->assertSame(0, KnowledgeArticle::live()->count());
    }

    // ── Taking it down ───────────────────────────────────────────────────

    public function test_withdrawing_needs_a_reason(): void
    {
        $article = $this->article();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('needs a reason');

        $article->withdraw('   ');
    }

    public function test_withdrawing_takes_it_down_and_keeps_the_reason(): void
    {
        $article = $this->article();
        ArticleReference::factory()->create(['referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey()]);
        $article->fresh()->approve($this->scholar());
        $article->fresh()->publish();

        $article->fresh()->withdraw('The citation was to the wrong collection.');

        $article = $article->fresh();

        $this->assertFalse($article->isLive());
        $this->assertStringContainsString('wrong collection', $article->withdrawn_reason);
    }

    // ── The queue a scholar works ────────────────────────────────────────

    public function test_the_review_queue_holds_only_what_is_waiting(): void
    {
        $waiting = $this->article();
        ArticleReference::factory()->create([
            'referenceable_type' => KnowledgeArticle::class,
            'referenceable_id' => $waiting->getKey(),
        ]);
        $waiting->sendForReview();

        $this->article();

        $done = $this->article();
        ArticleReference::factory()->create([
            'referenceable_type' => KnowledgeArticle::class,
            'referenceable_id' => $done->getKey(),
        ]);
        $done->fresh()->approve($this->scholar());

        $this->assertSame([$waiting->getKey()], KnowledgeArticle::awaitingAScholar()->pluck('id')->all());
    }

    // ── Nothing is seeded ────────────────────────────────────────────────

    /**
     * Not one article ships with this feature.
     *
     * AGENTS.md records that fabricated Dhivehi and invented guide steps
     * have already reached this codebase. Religious text is the worst
     * possible place to repeat that, so the machinery ships and the content
     * is a scholar's.
     */
    public function test_no_knowledge_article_is_seeded(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertSame(0, KnowledgeArticle::count());
        $this->assertSame(0, ArticleReference::count());
    }

    public function test_every_status_and_category_has_a_sentence(): void
    {
        foreach (KnowledgeArticle::STATUSES as $status) {
            $this->assertNotSame('Unknown', (new KnowledgeArticle(['status' => $status]))->statusLabel(), $status);
        }

        foreach (KnowledgeArticle::CATEGORIES as $category) {
            $this->assertNotSame('Unknown', (new KnowledgeArticle(['category' => $category]))->categoryLabel(), $category);
        }
    }
}
