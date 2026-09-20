<?php

namespace Tests\Feature;

use App\Filament\RelationManagers\ReferencesRelationManager;
use App\Filament\Resources\Learning\LearningModuleResource;
use App\Filament\Resources\Learning\Pages\EditLearningModule;
use App\Filament\Resources\Learning\Pages\ListLearningModules;
use App\Filament\Resources\Learning\RelationManagers\QuizQuestionsRelationManager;
use App\Filament\Resources\LearningPaths\Pages\ListLearningPaths;
use App\Models\ArticleReference;
use App\Models\LearningModule;
use App\Models\LearningPath;
use App\Models\Person;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Learning Academy screens — §7.3, §6.4.
 *
 * Same separation as the Knowledge Centre and the Ziyarah Guide: the
 * scholar who signs a module off cannot put it on a pilgrim's plan, and the
 * office that publishes cannot sign it off. A module teaching somebody how
 * to perform a rite is a claim they are about to act on, so nothing about
 * §7.3 makes it a lighter one.
 */
class LearningAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function sourcedModule(): LearningModule
    {
        $module = LearningModule::factory()->create();

        ArticleReference::factory()->create([
            'referenceable_type' => LearningModule::class,
            'referenceable_id' => $module->getKey(),
        ]);

        return $module->fresh();
    }

    // ── The separation §6.4 requires ─────────────────────────────────────

    public function test_the_scholar_signs_off_and_cannot_publish(): void
    {
        $scholar = $this->staff(Access::SCHOLAR);

        $this->assertTrue($scholar->can('learning.review'));
        $this->assertFalse($scholar->can('learning.publish'));
        $this->assertFalse($scholar->can('learning.create'));
    }

    public function test_operations_publishes_and_cannot_sign_off(): void
    {
        $operations = $this->staff(Access::OPERATIONS_MANAGER);

        $this->assertTrue($operations->can('learning.publish'));
        $this->assertFalse($operations->can('learning.review'));
    }

    public function test_the_content_manager_writes_and_does_neither(): void
    {
        $content = $this->staff(Access::CONTENT_MANAGER);

        $this->assertTrue($content->can('learning.create'));
        $this->assertFalse($content->can('learning.review'));
        $this->assertFalse($content->can('learning.publish'));
    }

    public function test_no_role_holds_both_review_and_publish(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $user = $this->staff($role);

            $this->assertFalse(
                $user->can('learning.review') && $user->can('learning.publish'),
                "[{$role}] can sign off a module and publish it, which makes the review a formality.",
            );
        }
    }

    public function test_there_is_no_delete_permission(): void
    {
        $this->assertNotContains('learning.delete', Access::PERMISSIONS);
    }

    // ── The screens agree with the model ─────────────────────────────────

    public function test_the_scholar_sees_the_sign_off_action_and_not_publish(): void
    {
        $module = $this->sourcedModule();
        $module->sendForReview();

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListLearningModules::class)
            ->assertOk()
            ->assertTableActionVisible('approve', $module->fresh())
            ->assertTableActionHidden('publish', $module->fresh());
    }

    public function test_signing_off_an_unsourced_module_leaves_it_in_review(): void
    {
        $module = LearningModule::factory()->create();
        $module->sendForReview();

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListLearningModules::class)
            ->callTableAction('approve', $module->fresh(), [
                'scholar_id' => Person::factory()->create()->getKey(),
            ]);

        $this->assertSame(LearningModule::IN_REVIEW, $module->fresh()->status);
    }

    public function test_publishing_a_signed_off_module_puts_it_on_the_plans(): void
    {
        $module = $this->sourcedModule();
        $module->approve(Person::factory()->create(['name' => 'Sheikh Placeholder']));

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListLearningModules::class)
            ->callTableAction('publish', $module->fresh())
            ->assertHasNoTableActionErrors();

        $this->assertTrue($module->fresh()->isLive());
    }

    public function test_taking_one_down_needs_a_reason(): void
    {
        $module = $this->sourcedModule();
        $module->approve(Person::factory()->create());
        $module->fresh()->publish();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListLearningModules::class)
            ->callTableAction('withdraw', $module->fresh(), ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertTrue($module->fresh()->isLive());
    }

    // ── What the table shows ─────────────────────────────────────────────

    /**
     * A bare number of days means nothing here; the column has to say what
     * the number is counting or "30" reads as a date.
     */
    public function test_the_schedule_column_says_what_the_number_counts(): void
    {
        $module = $this->sourcedModule();
        $module->forceFill(['days_before_departure' => 30])->save();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListLearningModules::class)
            ->assertOk()
            ->assertTableColumnStateSet('days_before_departure', '30 days before', $module->fresh());
    }

    public function test_a_module_shown_on_the_day_says_so_in_words(): void
    {
        $module = $this->sourcedModule();
        $module->forceFill(['days_before_departure' => 0])->save();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListLearningModules::class)
            ->assertTableColumnStateSet('days_before_departure', 'On the day', $module->fresh());
    }

    public function test_the_source_count_is_red_at_zero(): void
    {
        $sourced = $this->sourcedModule();
        $bare = LearningModule::factory()->create();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListLearningModules::class)
            ->assertTableColumnStateSet('references_count', 1, $sourced)
            ->assertTableColumnStateSet('references_count', 0, $bare);
    }

    public function test_an_unreviewed_module_says_nobody_rather_than_showing_a_blank(): void
    {
        $module = $this->sourcedModule();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListLearningModules::class)
            ->assertTableColumnStateSet('reviewer.name', 'Nobody yet', $module);
    }

    // ── The quiz relation manager ────────────────────────────────────────

    /**
     * A relation manager loads in its own Livewire request, so asserting on
     * the edit page would prove nothing about this table.
     */
    public function test_the_questions_table_names_what_is_not_usable_yet(): void
    {
        $module = $this->sourcedModule();

        $broken = QuizQuestion::factory()->create(['learning_module_id' => $module->getKey()]);
        QuizOption::factory()->count(2)->create(['quiz_question_id' => $broken->getKey()]);
        ArticleReference::factory()->create([
            'referenceable_type' => QuizQuestion::class,
            'referenceable_id' => $broken->getKey(),
        ]);

        $sound = QuizQuestion::factory()->create(['learning_module_id' => $module->getKey()]);
        QuizOption::factory()->correct()->create(['quiz_question_id' => $sound->getKey()]);
        QuizOption::factory()->create(['quiz_question_id' => $sound->getKey()]);
        ArticleReference::factory()->create([
            'referenceable_type' => QuizQuestion::class,
            'referenceable_id' => $sound->getKey(),
        ]);

        $component = Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(QuizQuestionsRelationManager::class, [
                'ownerRecord' => $module,
                'pageClass' => EditLearningModule::class,
            ])
            ->assertOk();

        $component->assertTableColumnStateSet('usable', 'Ready', $sound->fresh());

        $this->assertStringContainsString(
            'marked correct',
            (string) $broken->fresh()->whyNotUsable(),
        );
    }

    /**
     * Factories run unguarded and the admin forms do not, so a column left
     * out of `$fillable` is written happily everywhere except the one path
     * that matters.
     */
    public function test_a_question_written_through_the_form_keeps_its_answers(): void
    {
        $module = $this->sourcedModule();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(QuizQuestionsRelationManager::class, [
                'ownerRecord' => $module,
                'pageClass' => EditLearningModule::class,
            ])
            ->callTableAction('create', data: [
                'prompt' => ['en' => 'Placeholder question through the form?'],
                'explanation' => ['en' => 'Placeholder explanation through the form.'],
                'sort_order' => 2,
                'options' => [
                    ['text' => ['en' => 'Placeholder right answer.'], 'is_correct' => true, 'sort_order' => 0],
                    ['text' => ['en' => 'Placeholder wrong answer.'], 'is_correct' => false, 'sort_order' => 1],
                ],
                'references' => [],
            ])
            ->assertHasNoTableActionErrors();

        $written = $module->quizQuestions()->latest('id')->first();

        $this->assertNotNull($written);
        $this->assertSame('Placeholder question through the form?', $written->prompt);
        $this->assertSame(2, $written->sort_order);
        $this->assertCount(2, $written->options);
        $this->assertSame(1, $written->correctOptions()->count());
    }

    public function test_the_shared_sources_table_works_on_a_module(): void
    {
        $module = $this->sourcedModule();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ReferencesRelationManager::class, [
                'ownerRecord' => $module,
                'pageClass' => EditLearningModule::class,
            ])
            ->assertOk()
            ->assertCanSeeTableRecords($module->references);
    }

    // ── Paths ────────────────────────────────────────────────────────────

    /**
     * The number a pilgrim would actually see. A path of eight modules
     * where none is signed off is an empty path, and the list should say so
     * rather than showing an eight.
     */
    public function test_the_path_list_counts_what_a_pilgrim_would_see(): void
    {
        $path = LearningPath::factory()->create();

        $live = $this->sourcedModule();
        $live->approve(Person::factory()->create());
        $live->fresh()->publish();

        $drafted = $this->sourcedModule();

        $path->modules()->attach([$live->getKey() => ['sort_order' => 1], $drafted->getKey() => ['sort_order' => 2]]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListLearningPaths::class)
            ->assertOk()
            ->assertTableColumnStateSet('modules_count', 2, $path)
            ->assertTableColumnStateSet('published_modules_count', 1, $path);
    }

    // ── Navigation ───────────────────────────────────────────────────────

    public function test_the_badge_counts_what_is_waiting_on_a_scholar(): void
    {
        $this->assertNull(LearningModuleResource::getNavigationBadge());

        $module = $this->sourcedModule();

        $this->assertNull(LearningModuleResource::getNavigationBadge());

        $module->sendForReview();

        $this->assertSame('1', LearningModuleResource::getNavigationBadge());
    }

    public function test_a_role_without_the_permission_cannot_open_the_list(): void
    {
        $this->actingAs($this->staff(Access::TOUR_LEADER))
            ->get(LearningModuleResource::getUrl('index'))
            ->assertForbidden();
    }
}
