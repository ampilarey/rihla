<?php

namespace Tests\Feature;

use App\Exceptions\EditorialStandardNotMet;
use App\Filament\Pages\DepartureBoard;
use App\Models\ArticleReference;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\ItineraryItem;
use App\Models\LearningModule;
use App\Models\LearningPath;
use App\Models\ModuleCompletion;
use App\Models\Person;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Traveller;
use App\Models\User;
use App\Models\ZiyarahLocation;
use App\Services\Learning\Progress;
use App\Services\Portal\Gatekeeper;
use App\Support\Access;
use App\Support\StudyPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Umrah Learning Academy — §7.3.
 *
 * Three things these hold down, in order of how badly they fail:
 *
 * 1. **Unreviewed religious instruction never reaches a pilgrim.** A module
 *    teaching somebody how to perform a rite is a claim they are about to
 *    act on, so it passes the same §6.4 gate as everything else.
 * 2. **The plan is arithmetic on the departure date, and stays right.**
 *    Move the departure and every deadline moves, with nothing to re-run.
 * 3. **Nothing here is a gate.** No score blocks anybody, and the itinerary
 *    tie-in fails loudly rather than dropping a module in silence.
 */
class LearningAcademyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);
    }

    private function scholar(): Person
    {
        return Person::factory()->create(['name' => 'Sheikh Placeholder', 'role' => 'scholar']);
    }

    /** A module with a source, ready to be signed off. */
    private function sourced(array $attributes = []): LearningModule
    {
        $module = LearningModule::factory()->create($attributes);

        ArticleReference::factory()->create([
            'referenceable_type' => LearningModule::class,
            'referenceable_id' => $module->getKey(),
        ]);

        return $module->fresh();
    }

    private function live(array $attributes = []): LearningModule
    {
        $module = $this->sourced($attributes);
        $module->approve($this->scholar());
        $module->fresh()->publish();

        return $module->fresh();
    }

    /** A departure sixty days out, with a booking and a lead traveller. */
    private function booking(int $daysOut = 60): Booking
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->addDays($daysOut)->startOfDay(),
                'date_end' => now()->addDays($daysOut + 14)->startOfDay(),
            ])->getKey(),
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 2_850_000,
        ]);

        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create(['full_name' => 'A Traveller'])->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        return $booking->refresh();
    }

    private function enter(Booking $booking): void
    {
        $token = app(Gatekeeper::class)->issue($booking);

        $this->get("/en/portal/enter/{$token}")->assertRedirect('/en/portal');
    }

    // ── The editorial gate ───────────────────────────────────────────────

    public function test_a_module_with_no_source_cannot_be_approved(): void
    {
        $module = LearningModule::factory()->create();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('No source yet');

        $module->approve($this->scholar());
    }

    public function test_an_unapproved_module_cannot_be_published(): void
    {
        $module = $this->sourced();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('non-negotiable');

        $module->publish();
    }

    public function test_the_refusal_names_a_module_rather_than_a_record(): void
    {
        $module = $this->sourced();

        try {
            $module->publish();
            $this->fail('An unapproved module was published.');
        } catch (EditorialStandardNotMet $e) {
            $this->assertStringContainsString('module', $e->getMessage());
        }
    }

    // ── The plan is arithmetic on the departure date ─────────────────────

    /**
     * 45, not the factory's 30.
     *
     * Planting "always 30 days" left this test green while the module's own
     * number was being ignored, because the fixture happened to use the
     * default. A fixture that matches the constant proves nothing about
     * whether the constant is being read.
     */
    public function test_a_module_is_due_its_own_number_of_days_before_departure(): void
    {
        $booking = $this->booking(daysOut: 60);
        $this->live(['slug' => 'forty-five-out', 'days_before_departure' => 45]);

        $plan = StudyPlan::build($booking->departure);

        $this->assertSame(1, $plan->total());
        $this->assertSame(
            now()->addDays(15)->startOfDay()->toDateString(),
            $plan->items->first()->due_on->toDateString(),
        );
    }

    public function test_moving_the_departure_moves_every_deadline_with_nothing_to_rerun(): void
    {
        $booking = $this->booking(daysOut: 60);
        $this->live(['slug' => 'moves', 'days_before_departure' => 30]);

        $before = StudyPlan::build($booking->departure)->items->first()->due_on->toDateString();

        $booking->departure->forceFill(['date_start' => now()->addDays(40)->startOfDay()])->save();

        $after = StudyPlan::build($booking->departure->fresh())->items->first()->due_on->toDateString();

        $this->assertNotSame($before, $after);
        $this->assertSame(now()->addDays(10)->startOfDay()->toDateString(), $after);
    }

    public function test_a_module_already_due_reads_as_due_now_and_a_later_one_does_not(): void
    {
        $booking = $this->booking(daysOut: 20);

        $this->live(['slug' => 'overdue', 'days_before_departure' => 60]);
        $this->live(['slug' => 'later', 'days_before_departure' => 7]);

        $plan = StudyPlan::build($booking->departure);

        $this->assertSame(1, $plan->dueNowCount());
        $this->assertSame(1, $plan->inState(StudyPlan::LATER)->count());
    }

    public function test_reading_a_module_marks_it_done_for_that_person(): void
    {
        $booking = $this->booking();
        $module = $this->live(['slug' => 'to-read']);
        $traveller = $booking->leadTraveller()->traveller;

        $this->assertSame(0, StudyPlan::build($booking->departure, $traveller)->doneCount());

        app(Progress::class)->markRead($traveller, $module);

        $plan = StudyPlan::build($booking->departure, $traveller->fresh());

        $this->assertSame(1, $plan->doneCount());
        $this->assertSame(StudyPlan::DONE, $plan->items->first()->state);
    }

    /**
     * Progress belongs to the person, not the booking.
     *
     * Somebody who travels twice keeps what they learned the first time.
     * Making them start again would be both wrong and insulting.
     */
    public function test_progress_survives_into_a_second_journey(): void
    {
        $first = $this->booking();
        $module = $this->live(['slug' => 'remembered']);
        $traveller = $first->leadTraveller()->traveller;

        app(Progress::class)->markRead($traveller, $module);

        $second = Booking::factory()->create([
            'customer_id' => $traveller->customer_id,
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->addDays(120)->startOfDay(),
                'date_end' => now()->addDays(134)->startOfDay(),
            ])->getKey(),
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 1_000_000,
        ]);

        $this->assertSame(1, StudyPlan::build($second->departure, $traveller)->doneCount());
    }

    public function test_a_finished_journey_stops_nagging(): void
    {
        $booking = $this->booking(daysOut: -10);
        $this->live(['slug' => 'too-late', 'days_before_departure' => 30]);

        $plan = StudyPlan::build($booking->departure);

        $this->assertTrue($plan->isHistory());
        $this->assertStringNotContainsString('to read now', $plan->summary());
    }

    public function test_the_summary_counts_rather_than_grading(): void
    {
        $booking = $this->booking(daysOut: 20);
        $this->live(['slug' => 'one', 'days_before_departure' => 60]);
        $this->live(['slug' => 'two', 'days_before_departure' => 7]);

        $summary = StudyPlan::build($booking->departure)->summary();

        $this->assertStringContainsString('0 of 2 read', $summary);
        // Never a percentage. "27%" is a grade, and this is not a course
        // anybody is being marked on.
        $this->assertStringNotContainsString('%', $summary);
    }

    // ── The itinerary tie-in, and how it is allowed to fail ──────────────

    public function test_a_place_module_appears_only_when_the_trip_goes_there(): void
    {
        $location = ZiyarahLocation::factory()->create(['name' => ['en' => 'Placeholder Hill']]);
        $this->live(['slug' => 'about-the-hill', 'ziyarah_location_id' => $location->getKey()]);

        $away = $this->booking();

        $this->assertSame(0, StudyPlan::build($away->departure)->total());

        $visiting = $this->booking();
        ItineraryItem::create([
            'departure_id' => $visiting->departure_id,
            'day_number' => 3,
            'title' => ['en' => 'Morning at Placeholder Hill'],
            'city' => 'Madinah',
        ]);

        $this->assertSame(1, StudyPlan::build($visiting->departure->fresh())->total());
    }

    /**
     * A tie-in that quietly drops a module is the failure this codebase has
     * been bitten by before, so the miss is surfaced instead.
     */
    public function test_a_place_the_itinerary_never_names_is_reported_rather_than_dropped_in_silence(): void
    {
        $location = ZiyarahLocation::factory()->create(['name' => ['en' => 'Placeholder Hill']]);
        $this->live(['slug' => 'about-the-hill', 'ziyarah_location_id' => $location->getKey()]);

        $booking = $this->booking();

        $plan = StudyPlan::build($booking->departure);

        $this->assertSame(0, $plan->total());
        $this->assertCount(1, $plan->unmatchedLocations);
        $this->assertSame('Placeholder Hill', $plan->unmatchedLocations->first()->name);
    }

    public function test_a_module_about_no_place_is_never_filtered_out(): void
    {
        $this->live(['slug' => 'about-a-rite']);

        $plan = StudyPlan::build($this->booking()->departure);

        $this->assertSame(1, $plan->total());
        $this->assertCount(0, $plan->unmatchedLocations);
    }

    // ── The quiz teaches; it does not mark ───────────────────────────────

    private function questionWith(LearningModule $module, int $correct, int $wrong): QuizQuestion
    {
        $question = QuizQuestion::factory()->create(['learning_module_id' => $module->getKey()]);

        for ($i = 0; $i < $correct; $i++) {
            QuizOption::factory()->correct()->create(['quiz_question_id' => $question->getKey()]);
        }

        for ($i = 0; $i < $wrong; $i++) {
            QuizOption::factory()->create(['quiz_question_id' => $question->getKey()]);
        }

        ArticleReference::factory()->create([
            'referenceable_type' => QuizQuestion::class,
            'referenceable_id' => $question->getKey(),
        ]);

        return $question->fresh()->load('options');
    }

    public function test_a_question_with_no_right_answer_is_refused_before_a_pilgrim_meets_it(): void
    {
        $question = $this->questionWith($this->live(), correct: 0, wrong: 3);

        $this->assertStringContainsString('marked correct', (string) $question->whyNotUsable());
    }

    public function test_a_question_where_every_answer_is_right_teaches_nothing(): void
    {
        $question = $this->questionWith($this->live(), correct: 3, wrong: 0);

        $this->assertStringContainsString('teaches nothing', (string) $question->whyNotUsable());
    }

    public function test_a_question_needs_a_source_like_everything_else_that_makes_a_claim(): void
    {
        $module = $this->live();
        $question = QuizQuestion::factory()->create(['learning_module_id' => $module->getKey()]);
        QuizOption::factory()->correct()->create(['quiz_question_id' => $question->getKey()]);
        QuizOption::factory()->create(['quiz_question_id' => $question->getKey()]);

        $this->assertStringContainsString('no source', (string) $question->fresh()->whyNotUsable());
    }

    public function test_a_sound_question_is_usable(): void
    {
        $this->assertNull($this->questionWith($this->live(), correct: 1, wrong: 2)->whyNotUsable());
    }

    /**
     * Strict set equality on a multi-answer question.
     *
     * Partial credit on "how do I perform this rite" would tell somebody
     * they were mostly right about a thing they are about to do.
     */
    public function test_half_of_a_multiple_answer_question_is_not_half_right(): void
    {
        $module = $this->live();
        $question = $this->questionWith($module, correct: 2, wrong: 1);
        $correct = $question->correctOptions();

        $traveller = $this->booking()->leadTraveller()->traveller;

        $outcome = app(Progress::class)->recordQuiz($traveller, $module->fresh(), [
            $question->getKey() => [$correct->first()->getKey()],
        ]);

        $this->assertSame(1, $outcome['answered']);
        $this->assertSame(0, $outcome['correct']);
    }

    public function test_choosing_exactly_the_right_set_is_right(): void
    {
        $module = $this->live();
        $question = $this->questionWith($module, correct: 2, wrong: 1);

        $traveller = $this->booking()->leadTraveller()->traveller;

        $outcome = app(Progress::class)->recordQuiz($traveller, $module->fresh(), [
            $question->getKey() => $question->correctOptions()->pluck('id')->all(),
        ]);

        $this->assertSame(1, $outcome['correct']);
    }

    /** Not knowing and getting it wrong are different, and only one is worth showing somebody. */
    public function test_a_skipped_question_counts_as_unanswered_rather_than_wrong(): void
    {
        $module = $this->live();
        $this->questionWith($module, correct: 1, wrong: 1);
        $this->questionWith($module, correct: 1, wrong: 1);

        $traveller = $this->booking()->leadTraveller()->traveller;

        $outcome = app(Progress::class)->recordQuiz($traveller, $module->fresh(), []);

        $this->assertSame(0, $outcome['answered']);
        $this->assertSame(0, $outcome['correct']);
    }

    /** The last attempt, not the best: it is a prompt to go back, not an achievement. */
    public function test_a_second_attempt_replaces_the_first(): void
    {
        $module = $this->live();
        $question = $this->questionWith($module, correct: 1, wrong: 1);
        $traveller = $this->booking()->leadTraveller()->traveller;

        app(Progress::class)->recordQuiz($traveller, $module->fresh(), [
            $question->getKey() => $question->correctOptions()->pluck('id')->all(),
        ]);

        $this->assertSame(1, ModuleCompletion::sole()->questions_correct);

        app(Progress::class)->recordQuiz($traveller, $module->fresh(), [
            $question->getKey() => [$question->options->firstWhere('is_correct', false)->getKey()],
        ]);

        $this->assertSame(0, ModuleCompletion::sole()->questions_correct);
    }

    /** Reading is idempotent, and the first read is the one recorded. */
    public function test_reading_twice_does_not_move_the_date(): void
    {
        $module = $this->live();
        $traveller = $this->booking()->leadTraveller()->traveller;

        $first = app(Progress::class)->markRead($traveller, $module)->read_at;

        $this->travel(2)->hours();

        $second = app(Progress::class)->markRead($traveller, $module->fresh())->read_at;

        $this->assertSame($first->toDateTimeString(), $second->toDateTimeString());
    }

    // ── The portal pages ─────────────────────────────────────────────────

    public function test_the_plan_needs_a_portal_session(): void
    {
        $this->get(route('learning.index'))->assertRedirect(route('portal.locked'));
    }

    public function test_only_a_published_module_has_a_page(): void
    {
        $booking = $this->booking();
        $this->enter($booking);

        foreach ([LearningModule::DRAFT, LearningModule::IN_REVIEW, LearningModule::APPROVED, LearningModule::WITHDRAWN] as $status) {
            $module = $this->sourced(['slug' => 'hidden-'.$status]);
            // published_at stamped deliberately: a draft has no publish date,
            // so without it a query that forgot the status check entirely
            // would still exclude it and this would prove nothing.
            $module->forceFill(['status' => $status, 'published_at' => now()->subDay()])->save();

            $this->get(route('learning.show', ['slug' => $module->slug]))->assertNotFound();
        }
    }

    public function test_opening_a_module_is_what_marks_it_read(): void
    {
        $booking = $this->booking();
        $module = $this->live(['slug' => 'opened']);
        $this->enter($booking);

        $this->assertSame(0, ModuleCompletion::count());

        $this->get(route('learning.show', ['slug' => 'opened']))->assertOk();

        $completion = ModuleCompletion::sole();

        $this->assertSame($booking->leadTraveller()->traveller_id, $completion->traveller_id);
        $this->assertNotNull($completion->read_at);
    }

    public function test_the_plan_page_names_the_next_thing_to_read(): void
    {
        $booking = $this->booking(daysOut: 20);
        $this->live(['slug' => 'due', 'days_before_departure' => 60, 'title' => ['en' => 'Something due now']]);
        $this->live(['slug' => 'not-yet', 'days_before_departure' => 7, 'title' => ['en' => 'Something later']]);

        $this->enter($booking);

        $this->get(route('learning.index'))
            ->assertOk()
            ->assertSee('To read now')
            ->assertSee('Something due now')
            ->assertSee('Coming up')
            ->assertSee('Something later');
    }

    /**
     * A date that has passed cannot be phrased as a deadline.
     *
     * The first version printed "By 21 August" under a heading saying "to
     * read now", about a day thirty days gone. It read as a date still to
     * come, and no assertion in this file noticed — a screenshot did.
     */
    public function test_an_overdue_module_does_not_read_as_a_future_deadline(): void
    {
        $booking = $this->booking(daysOut: 10);
        $this->live(['slug' => 'long-overdue', 'days_before_departure' => 60]);

        $this->enter($booking);

        $response = $this->get(route('learning.index'))->assertOk();

        $response->assertSee('It was due');
        $response->assertDontSee('Read it by');
    }

    public function test_a_module_due_today_says_today(): void
    {
        $booking = $this->booking(daysOut: 30);
        $this->live(['slug' => 'due-today', 'days_before_departure' => 30]);

        $this->enter($booking);

        $this->get(route('learning.index'))->assertOk()->assertSee('Due today');
    }

    public function test_the_module_page_names_the_scholar_and_shows_its_sources(): void
    {
        $booking = $this->booking();
        $module = $this->live(['slug' => 'sourced-page']);

        ArticleReference::factory()->hadith(ArticleReference::DAIF)->create([
            'referenceable_type' => LearningModule::class,
            'referenceable_id' => $module->getKey(),
            'citation' => 'Placeholder narration for a test fixture',
        ]);

        $this->enter($booking);

        $this->get(route('learning.show', ['slug' => 'sourced-page']))
            ->assertOk()
            ->assertSee('Sheikh Placeholder')
            ->assertSee('Placeholder narration for a test fixture')
            ->assertSee("Da'if — weak")
            // Set apart rather than a pale pill on a cream page.
            ->assertSee('border-error/40', false);
    }

    public function test_submitting_the_quiz_says_how_it_went_and_explains(): void
    {
        $booking = $this->booking();
        $module = $this->live(['slug' => 'with-a-quiz']);
        $question = $this->questionWith($module, correct: 1, wrong: 1);

        $this->enter($booking);

        $this->post(route('learning.quiz', ['slug' => 'with-a-quiz']), [
            'answers' => [$question->getKey() => $question->correctOptions()->pluck('id')->all()],
        ])
            ->assertOk()
            ->assertSee('You got 1 of 1')
            ->assertSee('That is right')
            ->assertSee('Placeholder explanation for a test fixture.');
    }

    /** Said out loud on the page, because a quiz on a religious subject looks like a test. */
    public function test_the_page_says_the_quiz_is_not_a_test(): void
    {
        $booking = $this->booking();
        $module = $this->live(['slug' => 'not-a-test']);
        $this->questionWith($module, correct: 1, wrong: 1);

        $this->enter($booking);

        $this->get(route('learning.show', ['slug' => 'not-a-test']))
            ->assertOk()
            ->assertSee('Nothing here is marked or kept against you');
    }

    // ── Paths ────────────────────────────────────────────────────────────

    public function test_a_path_shows_only_the_modules_a_scholar_has_signed_off(): void
    {
        $path = LearningPath::factory()->create(['name' => ['en' => 'A path']]);

        $live = $this->live(['slug' => 'ready', 'title' => ['en' => 'A finished module']]);
        $drafted = $this->sourced(['slug' => 'drafted', 'title' => ['en' => 'An unfinished module']]);

        $path->modules()->attach([$live->getKey() => ['sort_order' => 1], $drafted->getKey() => ['sort_order' => 2]]);

        $this->enter($this->booking());

        $this->get(route('learning.index'))
            ->assertOk()
            ->assertSee('A finished module')
            ->assertDontSee('An unfinished module');
    }

    /**
     * The board tells the office about a tie-in that missed, because the
     * office is the only party that can fix a spelling. A test on the
     * page, not on the support class, because the promise in
     * {@see StudyPlan}'s docblock is that somebody is actually told.
     */
    public function test_the_departure_board_names_a_place_the_itinerary_missed(): void
    {
        $location = ZiyarahLocation::factory()->create(['name' => ['en' => 'Placeholder Hill']]);
        $this->live(['slug' => 'about-the-hill', 'ziyarah_location_id' => $location->getKey()]);

        $booking = $this->booking();

        Livewire::actingAs(
            User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER),
        )
            ->test(DepartureBoard::class)
            ->assertOk()
            ->assertSee('Placeholder Hill');

        $this->assertNotEmpty(StudyPlan::build($booking->departure)->unmatchedLocations);
    }

    public function test_every_audience_has_a_sentence(): void
    {
        foreach (LearningPath::AUDIENCES as $audience) {
            $this->assertNotSame('Unknown', (new LearningPath(['audience' => $audience]))->audienceLabel());
        }
    }

    // ── Nothing ships with this feature ──────────────────────────────────

    public function test_no_module_or_path_is_seeded(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertSame(0, LearningModule::count());
        $this->assertSame(0, LearningPath::count());
        $this->assertSame(0, QuizQuestion::count());
    }
}
