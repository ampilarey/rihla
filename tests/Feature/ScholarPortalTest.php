<?php

namespace Tests\Feature;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\ArticleReference;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\KnowledgeArticle;
use App\Models\LearningModule;
use App\Models\Person;
use App\Models\ScholarQuestion;
use App\Models\Traveller;
use App\Models\ZiyarahLocation;
use App\Services\Portal\Gatekeeper;
use App\Support\ReviewQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The Scholar Portal — §6.4.
 *
 * Two things these hold down:
 *
 * 1. **A question is a private message unless the person who asked said
 *    otherwise.** Consent is given once, at the moment of asking, and
 *    nobody in the office can grant it on somebody's behalf.
 * 2. **Every question ends.** Answered or declined with a reason — there is
 *    no path by which one quietly goes away, and the queue counts exactly
 *    what nobody has dealt with.
 */
class ScholarPortalTest extends TestCase
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

    private function booking(): Booking
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->addDays(40)->startOfDay(),
                'date_end' => now()->addDays(54)->startOfDay(),
            ])->getKey(),
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 1_000_000,
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

    // ── Consent is the asker's, and only theirs ──────────────────────────

    public function test_a_question_is_private_by_default(): void
    {
        $booking = $this->booking();
        $this->enter($booking);

        $this->post(route('learning.questions.store'), [
            'body' => 'Placeholder question long enough to pass validation.',
        ])->assertRedirect(route('learning.questions'));

        $question = ScholarQuestion::sole();

        $this->assertFalse($question->may_publish);
        $this->assertSame($booking->getKey(), $question->booking_id);
    }

    public function test_an_answered_question_cannot_be_published_without_consent(): void
    {
        $question = ScholarQuestion::factory()->create();
        $question->answerWith($this->scholar(), 'Placeholder answer.');

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('did not agree');

        $question->fresh()->publish();
    }

    public function test_consent_plus_an_answer_is_what_publishing_needs(): void
    {
        $question = ScholarQuestion::factory()->mayBePublished()->create();

        // Consent alone is not enough: there is nothing to publish yet.
        try {
            $question->publish();
            $this->fail('An unanswered question was published.');
        } catch (EditorialStandardNotMet $e) {
            $this->assertStringContainsString('answered', $e->getMessage());
        }

        $question->answerWith($this->scholar(), 'Placeholder answer.');
        $question->fresh()->publish();

        $this->assertTrue($question->fresh()->isPublic());
    }

    /**
     * The consent box is unticked unless the person ticked it.
     *
     * A missing checkbox is a no. Reading an absent key as anything else is
     * how consent becomes a default.
     */
    public function test_the_consent_box_has_to_be_ticked(): void
    {
        $booking = $this->booking();
        $this->enter($booking);

        $this->post(route('learning.questions.store'), [
            'body' => 'Placeholder question long enough to pass validation.',
            'may_publish' => '1',
        ]);

        $this->assertTrue(ScholarQuestion::sole()->may_publish);
    }

    public function test_taking_a_published_answer_down_leaves_the_consent_alone(): void
    {
        $question = ScholarQuestion::factory()->mayBePublished()->create();
        $question->answerWith($this->scholar(), 'Placeholder answer.');
        $question->fresh()->publish();

        $question->fresh()->unpublish();

        $this->assertFalse($question->fresh()->isPublic());
        // Consent is the asker's and was not withdrawn — the office took
        // the page down, which is a different act.
        $this->assertTrue($question->fresh()->may_publish);
    }

    // ── Every question ends ──────────────────────────────────────────────

    public function test_an_answer_cannot_be_blank(): void
    {
        $question = ScholarQuestion::factory()->create();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('cannot be blank');

        $question->answerWith($this->scholar(), '   ');
    }

    public function test_declining_needs_a_reason(): void
    {
        $question = ScholarQuestion::factory()->create();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('needs a reason');

        $question->decline('  ');
    }

    public function test_a_declined_question_tells_the_asker_what_to_do(): void
    {
        $booking = $this->booking();
        $question = ScholarQuestion::factory()->create(['booking_id' => $booking->getKey()]);

        $question->decline('This is one for your local imam — placeholder text.');

        $this->enter($booking);

        $this->get(route('learning.questions'))
            ->assertOk()
            ->assertSee('This is one for your local imam — placeholder text.')
            ->assertSee('We could not answer this one');
    }

    public function test_the_queue_counts_only_what_nobody_has_dealt_with(): void
    {
        ScholarQuestion::factory()->count(3)->create();

        $this->assertSame(3, ScholarQuestion::waiting()->count());

        ScholarQuestion::first()->answerWith($this->scholar(), 'Placeholder answer.');
        ScholarQuestion::skip(1)->first()->decline('Placeholder reason.');

        $this->assertSame(1, ScholarQuestion::waiting()->count());
    }

    public function test_an_answer_names_the_scholar_to_the_person_who_asked(): void
    {
        $booking = $this->booking();
        $question = ScholarQuestion::factory()->create(['booking_id' => $booking->getKey()]);

        $question->answerWith($this->scholar(), 'Placeholder answer for the portal page.');

        $this->enter($booking);

        $this->get(route('learning.questions'))
            ->assertOk()
            ->assertSee('Sheikh Placeholder')
            ->assertSee('Placeholder answer for the portal page.');
    }

    /**
     * An answer carries sources like every other religious claim here —
     * more so, because it is about something the person has already done.
     */
    public function test_an_answers_sources_reach_the_person_who_asked(): void
    {
        $booking = $this->booking();
        $question = ScholarQuestion::factory()->create(['booking_id' => $booking->getKey()]);
        $question->answerWith($this->scholar(), 'Placeholder answer.');

        ArticleReference::factory()->create([
            'referenceable_type' => ScholarQuestion::class,
            'referenceable_id' => $question->getKey(),
            'citation' => 'Placeholder citation on an answer',
        ]);

        $this->enter($booking);

        $this->get(route('learning.questions'))->assertOk()->assertSee('Placeholder citation on an answer');
    }

    /** The grading rule follows the reference table wherever it goes. */
    public function test_an_answers_hadith_still_needs_a_grading(): void
    {
        $question = ScholarQuestion::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry a grading');

        ArticleReference::factory()->create([
            'referenceable_type' => ScholarQuestion::class,
            'referenceable_id' => $question->getKey(),
            'kind' => ArticleReference::HADITH,
            'grading' => null,
        ]);
    }

    // ── The portal ───────────────────────────────────────────────────────

    public function test_asking_needs_a_portal_session(): void
    {
        $this->get(route('learning.questions'))->assertRedirect(route('portal.locked'));
    }

    public function test_a_pilgrim_sees_only_their_own_questions(): void
    {
        $mine = $this->booking();
        $theirs = $this->booking();

        ScholarQuestion::factory()->create(['booking_id' => $mine->getKey(), 'body' => 'Placeholder question of mine.']);
        ScholarQuestion::factory()->create(['booking_id' => $theirs->getKey(), 'body' => 'Placeholder question of theirs.']);

        $this->enter($mine);

        $this->get(route('learning.questions'))
            ->assertOk()
            ->assertSee('Placeholder question of mine.')
            ->assertDontSee('Placeholder question of theirs.');
    }

    public function test_a_question_that_is_too_short_is_refused(): void
    {
        $this->enter($this->booking());

        $this->post(route('learning.questions.store'), ['body' => 'hi'])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, ScholarQuestion::count());
    }

    /** Said on the screen, because consent nobody reads is not consent. */
    public function test_the_form_says_what_happens_to_a_private_question(): void
    {
        $this->enter($this->booking());

        $this->get(route('learning.questions'))
            ->assertOk()
            ->assertSee('stays between you, the office and the scholar');
    }

    // ── The one queue ────────────────────────────────────────────────────

    /**
     * A reviewer who has to check four screens checks none, and the
     * editorial gate becomes a bottleneck nobody can see the length of.
     */
    public function test_the_queue_gathers_every_kind_of_thing_waiting(): void
    {
        $this->waitingArticle('An article waiting');
        $this->waitingLocation('A location waiting');
        $this->waitingModule('A module waiting');
        ScholarQuestion::factory()->create(['body' => 'A question waiting for somebody.']);

        $queue = ReviewQueue::build();

        $this->assertCount(4, $queue);
        $this->assertSame(4, ReviewQueue::count());
        $this->assertEqualsCanonicalizing(
            [ReviewQueue::ARTICLE, ReviewQueue::LOCATION, ReviewQueue::MODULE, ReviewQueue::QUESTION],
            $queue->pluck('kind')->all(),
        );
    }

    public function test_the_queue_puts_the_longest_wait_first(): void
    {
        $this->travelTo(now()->subDays(30));
        $old = ScholarQuestion::factory()->create(['body' => 'The oldest question here.']);

        $this->travelTo(now()->addDays(29));
        ScholarQuestion::factory()->create(['body' => 'A newer question here.']);

        $this->travelBack();

        $queue = ReviewQueue::build();

        $this->assertSame('The oldest question here.', $queue->first()->title);
        $this->assertTrue($queue->first()->isStale());
        $this->assertFalse($queue->last()->isStale());
    }

    public function test_nothing_signed_off_stays_in_the_queue(): void
    {
        $article = $this->waitingArticle('Signed off shortly');

        $this->assertSame(1, ReviewQueue::count());

        $article->approve($this->scholar());

        $this->assertSame(0, ReviewQueue::count());
    }

    /**
     * Somebody's private question does not belong in full on a list screen
     * anybody with the permission can leave open.
     */
    public function test_the_queue_truncates_a_pilgrims_question(): void
    {
        ScholarQuestion::factory()->create(['body' => str_repeat('Placeholder sentence. ', 30)]);

        $title = ReviewQueue::build()->first()->title;

        $this->assertLessThan(100, mb_strlen($title));
        $this->assertStringEndsWith('...', $title);
    }

    private function waitingArticle(string $title): KnowledgeArticle
    {
        $article = KnowledgeArticle::factory()->create(['title' => ['en' => $title]]);
        ArticleReference::factory()->create([
            'referenceable_type' => KnowledgeArticle::class,
            'referenceable_id' => $article->getKey(),
        ]);
        $article->sendForReview();

        return $article->fresh();
    }

    private function waitingLocation(string $name): ZiyarahLocation
    {
        $location = ZiyarahLocation::factory()->create(['name' => ['en' => $name]]);
        $location->sendForReview();

        return $location->fresh();
    }

    private function waitingModule(string $title): LearningModule
    {
        $module = LearningModule::factory()->create(['title' => ['en' => $title]]);
        $module->sendForReview();

        return $module->fresh();
    }

    public function test_every_status_has_a_sentence(): void
    {
        foreach (ScholarQuestion::STATUSES as $status) {
            $this->assertNotSame('Unknown', (new ScholarQuestion(['status' => $status]))->statusLabel());
        }
    }

    public function test_no_question_is_seeded(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertSame(0, ScholarQuestion::count());
    }
}
