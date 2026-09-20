<?php

namespace Tests\Feature;

use App\Filament\Pages\ScholarDesk;
use App\Filament\Resources\Questions\Pages\ListScholarQuestions;
use App\Filament\Resources\Questions\ScholarQuestionResource;
use App\Models\ArticleReference;
use App\Models\KnowledgeArticle;
use App\Models\Person;
use App\Models\ScholarQuestion;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Scholar Portal's screens — §6.4.
 *
 * The separation here is a different one from the other content resources:
 * a scholar **answers**, the office **publishes**, and publishing is gated
 * on the asker's consent besides — which no permission can grant, because
 * it belongs to the person who asked.
 */
class ScholarDeskTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    // ── Who may do what ──────────────────────────────────────────────────

    public function test_the_scholar_answers_and_cannot_publish(): void
    {
        $scholar = $this->staff(Access::SCHOLAR);

        $this->assertTrue($scholar->can('question.answer'));
        $this->assertFalse($scholar->can('question.publish'));
    }

    public function test_operations_publishes_and_cannot_answer(): void
    {
        $operations = $this->staff(Access::OPERATIONS_MANAGER);

        $this->assertTrue($operations->can('question.publish'));
        $this->assertFalse($operations->can('question.answer'));
    }

    public function test_no_role_holds_both_answer_and_publish(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $user = $this->staff($role);

            $this->assertFalse(
                $user->can('question.answer') && $user->can('question.publish'),
                "[{$role}] can answer a question about religion and put the answer on the site.",
            );
        }
    }

    /**
     * Nobody writes a question in the office and nobody deletes one: a
     * question that vanished is one the person who asked is still waiting
     * on.
     */
    public function test_there_is_no_create_or_delete_permission(): void
    {
        $this->assertNotContains('question.create', Access::PERMISSIONS);
        $this->assertNotContains('question.delete', Access::PERMISSIONS);
    }

    // ── The desk ─────────────────────────────────────────────────────────

    public function test_a_scholar_can_open_the_desk_and_a_tour_leader_cannot(): void
    {
        $this->actingAs($this->staff(Access::SCHOLAR))
            ->get(ScholarDesk::getUrl())
            ->assertOk();

        $this->actingAs($this->staff(Access::TOUR_LEADER))
            ->get(ScholarDesk::getUrl())
            ->assertForbidden();
    }

    public function test_the_desk_lists_what_is_waiting_and_says_how_long(): void
    {
        $this->travelTo(now()->subDays(30));
        ScholarQuestion::factory()->create(['body' => 'A question that has been waiting a month.']);
        $this->travelBack();

        $article = KnowledgeArticle::factory()->create(['title' => ['en' => 'An article in review']]);
        ArticleReference::factory()->create([
            'referenceable_type' => KnowledgeArticle::class,
            'referenceable_id' => $article->getKey(),
        ]);
        $article->sendForReview();

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ScholarDesk::class)
            ->assertOk()
            ->assertSee('An article in review')
            ->assertSee('A question that has been waiting a month.')
            // The number that matters: how long somebody has been ignored.
            // No "ago" — the sentence already starts with "waiting", and
            // the first version read "waiting 1 month ago".
            ->assertSee('waiting 4 weeks')
            // The assertion that carries the weight: "waiting 4 weeks" is
            // a prefix of "waiting 4 weeks ago", so only this one can tell
            // the two apart.
            ->assertDontSee('waiting 4 weeks ago')
            ->assertSee('over a fortnight');
    }

    public function test_the_desk_says_so_when_nothing_is_waiting(): void
    {
        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ScholarDesk::class)
            ->assertOk()
            ->assertSee('Nobody is waiting on a scholar');
    }

    public function test_the_badge_counts_everything_across_all_four_queues(): void
    {
        $this->assertNull(ScholarDesk::getNavigationBadge());

        ScholarQuestion::factory()->count(2)->create();

        $this->assertSame('2', ScholarDesk::getNavigationBadge());
    }

    // ── The questions screen ─────────────────────────────────────────────

    public function test_answering_through_the_screen_names_the_scholar(): void
    {
        $question = ScholarQuestion::factory()->create();
        $named = Person::factory()->create(['name' => 'Sheikh Placeholder']);

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListScholarQuestions::class)
            ->callTableAction('answer', $question, [
                'scholar_id' => $named->getKey(),
                'answer' => 'Placeholder answer through the screen.',
            ])
            ->assertHasNoTableActionErrors();

        $question = $question->fresh();

        $this->assertSame(ScholarQuestion::ANSWERED, $question->status);
        $this->assertSame('Sheikh Placeholder', $question->scholar->name);
    }

    public function test_declining_through_the_screen_needs_a_reason(): void
    {
        $question = ScholarQuestion::factory()->create();

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListScholarQuestions::class)
            ->callTableAction('decline', $question, ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertTrue($question->fresh()->isWaiting());
    }

    /**
     * Hidden outright, not shown and refused.
     *
     * An office screen offering a button that cannot be pressed teaches
     * people that consent is an obstacle to get round.
     */
    public function test_the_publish_button_is_absent_without_the_askers_consent(): void
    {
        $withheld = ScholarQuestion::factory()->create();
        $withheld->answerWith(Person::factory()->create(), 'Placeholder answer.');

        $given = ScholarQuestion::factory()->mayBePublished()->create();
        $given->answerWith(Person::factory()->create(), 'Placeholder answer.');

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListScholarQuestions::class)
            ->assertTableActionHidden('publish', $withheld->fresh())
            ->assertTableActionVisible('publish', $given->fresh());
    }

    /** Three states, and the middle one is the point. */
    public function test_the_consent_column_distinguishes_agreed_from_published(): void
    {
        $withheld = ScholarQuestion::factory()->create();

        $agreed = ScholarQuestion::factory()->mayBePublished()->create();
        $agreed->answerWith(Person::factory()->create(), 'Placeholder answer.');

        $published = ScholarQuestion::factory()->mayBePublished()->create();
        $published->answerWith(Person::factory()->create(), 'Placeholder answer.');
        $published->fresh()->publish();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListScholarQuestions::class)
            ->assertTableColumnStateSet('may_publish', 'Private — they said no', $withheld)
            ->assertTableColumnStateSet('may_publish', 'They agreed; not up yet', $agreed->fresh())
            ->assertTableColumnStateSet('may_publish', 'On the site', $published->fresh());
    }

    public function test_the_waiting_column_says_how_long_rather_than_when(): void
    {
        $this->travelTo(now()->subDays(20));
        $waiting = ScholarQuestion::factory()->create();
        $this->travelBack();

        $answered = ScholarQuestion::factory()->create();
        $answered->answerWith(Person::factory()->create(), 'Placeholder answer.');

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListScholarQuestions::class)
            ->assertTableColumnStateSet('created_at', '2 weeks ago', $waiting)
            // Not "how long has this been answered for": once it is dealt
            // with, the wait is not a number anybody needs.
            ->assertTableColumnStateSet('created_at', '—', $answered->fresh());
    }

    public function test_the_badge_on_the_questions_resource_counts_the_unanswered(): void
    {
        $this->assertNull(ScholarQuestionResource::getNavigationBadge());

        ScholarQuestion::factory()->count(3)->create();
        ScholarQuestion::first()->decline('Placeholder reason.');

        $this->assertSame('2', ScholarQuestionResource::getNavigationBadge());
    }

    public function test_a_role_without_the_permission_cannot_open_the_questions(): void
    {
        $this->actingAs($this->staff(Access::TOUR_LEADER))
            ->get(ScholarQuestionResource::getUrl('index'))
            ->assertForbidden();
    }
}
