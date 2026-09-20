<?php

namespace Tests\Feature;

use App\Filament\Resources\Knowledge\KnowledgeArticleResource;
use App\Filament\Resources\Knowledge\Pages\ListKnowledgeArticles;
use App\Models\ArticleReference;
use App\Models\KnowledgeArticle;
use App\Models\Person;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Knowledge Centre screens — §7.1, §6.4.
 *
 * The separation these tests hold down: **the scholar who signs an article
 * off cannot put it on the site, and the office that publishes cannot sign
 * it off.** §6.4 calls editorial review before publish non-negotiable, and
 * a reviewer holding the publish button performs the check on themselves.
 */
class KnowledgeCentreAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function sourcedArticle(): KnowledgeArticle
    {
        $article = KnowledgeArticle::factory()->create();
        ArticleReference::factory()->create(['referenceable_type' => KnowledgeArticle::class, 'referenceable_id' => $article->getKey()]);

        return $article->fresh();
    }

    // ── The separation §6.4 requires ─────────────────────────────────────

    public function test_the_scholar_signs_off_and_cannot_publish(): void
    {
        $scholar = $this->staff(Access::SCHOLAR);

        $this->assertTrue($scholar->can('knowledge.review'));
        $this->assertFalse($scholar->can('knowledge.publish'));
        $this->assertFalse($scholar->can('knowledge.create'));
    }

    public function test_operations_publishes_and_cannot_sign_off(): void
    {
        $operations = $this->staff(Access::OPERATIONS_MANAGER);

        $this->assertTrue($operations->can('knowledge.publish'));
        $this->assertFalse($operations->can('knowledge.review'));
    }

    /**
     * Owning the website's words is not enough to sign off religious
     * content.
     */
    public function test_the_content_manager_drafts_and_does_neither(): void
    {
        $content = $this->staff(Access::CONTENT_MANAGER);

        $this->assertTrue($content->can('knowledge.create'));
        $this->assertTrue($content->can('knowledge.update'));
        $this->assertFalse($content->can('knowledge.review'));
        $this->assertFalse($content->can('knowledge.publish'));
    }

    /**
     * The check that would catch somebody quietly widening a role later.
     *
     * Super Admin is excluded because `Gate::before` grants it everything
     * outright — the deliberate design recorded elsewhere. The guarantee
     * here is that no *other* role can hold both halves.
     */
    public function test_no_role_holds_both_review_and_publish(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $user = $this->staff($role);

            $this->assertFalse(
                $user->can('knowledge.review') && $user->can('knowledge.publish'),
                "[{$role}] can sign off religious content and publish it, which makes the review a formality.",
            );
        }
    }

    // ── The screens agree with the model ─────────────────────────────────

    public function test_the_scholar_sees_the_sign_off_action_and_not_publish(): void
    {
        $article = $this->sourcedArticle();
        $article->sendForReview();

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListKnowledgeArticles::class)
            ->assertOk()
            ->assertTableActionVisible('approve', $article->fresh())
            ->assertTableActionHidden('publish', $article->fresh());
    }

    public function test_operations_sees_publish_only_once_it_is_approved(): void
    {
        $article = $this->sourcedArticle();
        $operations = $this->staff(Access::OPERATIONS_MANAGER);

        Livewire::actingAs($operations)
            ->test(ListKnowledgeArticles::class)
            ->assertTableActionHidden('publish', $article);

        $article->approve(Person::factory()->create());

        Livewire::actingAs($operations)
            ->test(ListKnowledgeArticles::class)
            ->assertTableActionVisible('publish', $article->fresh());
    }

    /**
     * Refusing in words, not a validation code.
     *
     * The person on the other end of this is deciding whether to publish
     * something about religion; "the given data was invalid" tells them
     * nothing about what is wrong.
     */
    public function test_signing_off_an_unsourced_article_says_why(): void
    {
        $article = KnowledgeArticle::factory()->create();
        $article->sendForReview();

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListKnowledgeArticles::class)
            ->callTableAction('approve', $article->fresh(), [
                'scholar_id' => Person::factory()->create()->getKey(),
            ]);

        $this->assertSame(KnowledgeArticle::IN_REVIEW, $article->fresh()->status);
    }

    public function test_signing_off_a_sourced_article_works_and_names_the_scholar(): void
    {
        $article = $this->sourcedArticle();
        $article->sendForReview();

        $named = Person::factory()->create(['name' => 'Sheikh Placeholder']);

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListKnowledgeArticles::class)
            ->callTableAction('approve', $article->fresh(), ['scholar_id' => $named->getKey()])
            ->assertHasNoTableActionErrors();

        $this->assertSame(KnowledgeArticle::APPROVED, $article->fresh()->status);
        $this->assertSame('Sheikh Placeholder', $article->fresh()->reviewer->name);
    }

    public function test_taking_one_down_needs_a_reason(): void
    {
        $article = $this->sourcedArticle();
        $article->approve(Person::factory()->create());
        $article->fresh()->publish();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListKnowledgeArticles::class)
            ->callTableAction('withdraw', $article->fresh(), ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertTrue($article->fresh()->isLive());
    }

    // ── What the table shows ─────────────────────────────────────────────

    /**
     * Zero sources is the state the whole apparatus exists to keep off the
     * site, so it is visible at a glance rather than one click in.
     */
    public function test_the_source_count_is_on_the_row(): void
    {
        $sourced = $this->sourcedArticle();
        $bare = KnowledgeArticle::factory()->create();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListKnowledgeArticles::class)
            ->assertOk()
            ->assertTableColumnStateSet('references_count', 1, $sourced)
            ->assertTableColumnStateSet('references_count', 0, $bare);
    }

    /** "Nobody yet" is a value, so the column's colour applies to it. */
    public function test_an_unreviewed_article_says_nobody_rather_than_showing_a_blank(): void
    {
        $article = $this->sourcedArticle();
        $reviewed = $this->sourcedArticle();
        $reviewed->approve(Person::factory()->create(['name' => 'Sheikh Placeholder']));

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListKnowledgeArticles::class)
            ->assertOk()
            ->assertTableColumnStateSet('reviewer.name', 'Nobody yet', $article)
            ->assertTableColumnStateSet('reviewer.name', 'Sheikh Placeholder', $reviewed->fresh());
    }

    public function test_the_badge_counts_articles_waiting_on_a_scholar(): void
    {
        $waiting = $this->sourcedArticle();
        $waiting->sendForReview();

        $this->sourcedArticle();

        $this->assertSame('1', KnowledgeArticleResource::getNavigationBadge());
    }

    /**
     * The breadcrumb said "Articles" — the name of the *blog* resource,
     * three items up the same navigation group. Two different things
     * called the same thing matters more here than anywhere else in this
     * panel, because one of them has an editorial standard and the other
     * does not.
     */
    public function test_it_is_not_called_the_same_thing_as_the_blog(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(KnowledgeArticleResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Knowledge Centre')
            ->assertDontSee('<li>Articles</li>', false);

        $this->assertSame('Knowledge Centre', KnowledgeArticleResource::getBreadcrumb());
    }

    public function test_booking_staff_cannot_reach_it(): void
    {
        $this->actingAs($this->staff(Access::BOOKING_STAFF))
            ->get(KnowledgeArticleResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_the_scholar_can_reach_it_over_http(): void
    {
        $this->actingAs($this->staff(Access::SCHOLAR))
            ->get(KnowledgeArticleResource::getUrl('index'))
            ->assertOk();
    }

    /**
     * The scholar's login reaches the Knowledge Centre and nothing else.
     *
     * They are not staff: no bookings, no customers, no money, no website.
     */
    public function test_the_scholar_cannot_reach_anything_else(): void
    {
        $scholar = $this->staff(Access::SCHOLAR);

        foreach (['booking.viewAny', 'customer.viewAny', 'payment.viewAny', 'trip.update', 'notice.viewAny'] as $permission) {
            $this->assertFalse($scholar->can($permission), "A scholar holds [{$permission}].");
        }
    }
}
