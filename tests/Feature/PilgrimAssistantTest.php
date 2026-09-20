<?php

namespace Tests\Feature;

use App\Models\AssistantExchange;
use App\Models\KnowledgeArticle;
use App\Models\Person;
use App\Models\Traveller;
use App\Services\Assistant\AnthropicProvider;
use App\Services\Assistant\Corpus;
use App\Services\Assistant\PilgrimAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The pilgrim assistant — §9.6, and mostly the half of §9.6 that says no.
 *
 * §9.6: answers "strictly from scholar-approved Knowledge Centre content,
 * with citations and a hard 'I'll connect you to an advisor' fallback",
 * and "**Never let it improvise rulings.**"
 *
 * Every one of those is a constraint in code rather than wording in a
 * prompt, and every one has a test here that plants its absence. The two
 * that matter most:
 *
 * - **An unapproved draft must never reach the model.** A prompt saying
 *   "only use approved content" is a request; the query is a fact.
 * - **An answer that cites nothing must be discarded.** An uncited
 *   sentence about a rite is the improvised ruling §9.6 forbids, and
 *   reading it will not tell you which it is.
 */
class PilgrimAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the network. A test that would is a test that
        // could quietly start costing money and leaking a prompt.
        Http::preventStrayRequests();
    }

    private function approvedArticle(string $title, string $body): KnowledgeArticle
    {
        $article = KnowledgeArticle::factory()->create([
            'title' => ['en' => $title],
            'summary' => ['en' => 'Placeholder summary for a test fixture.'],
            'body' => ['en' => $body],
        ]);

        $article->forceFill([
            'status' => KnowledgeArticle::PUBLISHED,
            'reviewed_by' => Person::factory()->create()->getKey(),
            'reviewed_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ])->save();

        return $article->refresh();
    }

    private function configured(): void
    {
        config([
            'assistant.enabled' => true,
            'assistant.provider' => 'anthropic',
            'assistant.anthropic.key' => 'test-key',
            'assistant.anthropic.model' => 'claude-sonnet-5',
            'assistant.anthropic.base_url' => 'https://api.anthropic.test',
        ]);
    }

    private function modelSays(string $text): void
    {
        Http::fake([
            'api.anthropic.test/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $text]],
            ]),
        ]);
    }

    // ── The state Rihla is actually in ───────────────────────────────────

    /**
     * Today it answers nothing, because nothing has been approved.
     *
     * This is the feature working. An assistant that began answering the
     * moment a key was pasted in, from a corpus of nothing, is the failure
     * the whole design exists to prevent.
     */
    public function test_with_no_approved_content_every_question_goes_to_a_person(): void
    {
        $this->configured();

        $answer = PilgrimAssistant::make()->ask('What should I do at the miqat?');

        $this->assertTrue($answer->referred);
        $this->assertStringContainsString('Nothing a scholar has approved', (string) $answer->because);
        $this->assertStringContainsString('does not guess about religion', (string) $answer->because);

        // And the model was never asked — Http::preventStrayRequests would
        // have failed the test, but assert it directly so the reason is
        // legible when it changes.
        Http::assertNothingSent();
    }

    /**
     * The constraint that a prompt cannot enforce.
     *
     * A draft, an article in review and a withdrawn one are invisible to
     * the corpus. Nothing the model is told can reach them.
     */
    public function test_an_unapproved_draft_never_reaches_the_model(): void
    {
        $this->configured();

        KnowledgeArticle::factory()->create([
            'title' => ['en' => 'Miqat placeholder'],
            'body' => ['en' => 'Placeholder body about the miqat for a test fixture.'],
            'status' => KnowledgeArticle::IN_REVIEW,
        ]);

        $answer = PilgrimAssistant::make()->ask('What should I do at the miqat?');

        $this->assertTrue($answer->referred);
        $this->assertTrue($answer->sources->isEmpty());
        Http::assertNothingSent();
    }

    public function test_the_corpus_only_returns_live_content(): void
    {
        KnowledgeArticle::factory()->create([
            'title' => ['en' => 'Passport placeholder'],
            'body' => ['en' => 'Placeholder body about the passport.'],
            'status' => KnowledgeArticle::DRAFT,
        ]);

        $this->assertTrue(Corpus::matching('passport')->isEmpty());

        $this->approvedArticle('Passport placeholder', 'Placeholder body about the passport.');

        $this->assertSame(1, Corpus::matching('passport')->count());
    }

    // ── The switch, and what it does not change ──────────────────────────

    public function test_switched_off_it_hands_everything_over(): void
    {
        config(['assistant.enabled' => false]);

        $this->approvedArticle('Passport placeholder', 'Placeholder body about the passport.');

        $answer = PilgrimAssistant::make()->ask('What about my passport?');

        $this->assertTrue($answer->referred);
        $this->assertStringContainsString('switched off', (string) $answer->because);
        Http::assertNothingSent();
    }

    /** A key with no approved content is still no answer. */
    public function test_a_configured_key_against_an_empty_corpus_still_refuses(): void
    {
        $this->configured();
        $this->modelSays('I could answer this. [1]');

        $answer = PilgrimAssistant::make()->ask('What should I do at the miqat?');

        $this->assertTrue($answer->referred);
        $this->assertStringContainsString('Nothing a scholar has approved', (string) $answer->because);
        Http::assertNothingSent();
    }

    /** Approved content with no key is a referral that says which is missing. */
    public function test_approved_content_with_no_provider_refers_and_still_shows_the_reading(): void
    {
        config(['assistant.enabled' => true, 'assistant.anthropic.key' => null]);

        $this->approvedArticle('Passport placeholder', 'Placeholder body about the passport.');

        $answer = PilgrimAssistant::make()->ask('What about my passport?');

        $this->assertTrue($answer->referred);
        $this->assertStringContainsString('No assistant has been set up', (string) $answer->because);

        // The passages are still offered: the pilgrim may well want to read
        // them, and finding them is most of what they asked for.
        $this->assertSame(1, $answer->sources->count());
        $this->assertSame('Passport placeholder', $answer->sources->first()->title);
    }

    // ── The citation constraint ──────────────────────────────────────────

    /**
     * The one that throws away work, and should.
     *
     * An uncited sentence about a rite is the improvised ruling §9.6
     * forbids, and it is indistinguishable from a good one by reading it.
     */
    public function test_an_answer_that_cites_nothing_is_discarded(): void
    {
        $this->configured();
        $this->approvedArticle('Passport placeholder', 'Placeholder body about the passport.');
        $this->modelSays('Your passport must be valid for six months. Everybody knows this.');

        $answer = PilgrimAssistant::make()->ask('What about my passport?');

        $this->assertTrue($answer->referred);
        $this->assertStringContainsString('without pointing at an approved source', (string) $answer->because);
        $this->assertStringNotContainsString('six months', $answer->text);
    }

    /** A marker that names a passage it was not given is not a citation. */
    public function test_a_citation_to_a_passage_that_does_not_exist_is_not_a_citation(): void
    {
        $this->assertFalse(PilgrimAssistant::citesASource('As the guidance says [7].', 2));
        $this->assertTrue(PilgrimAssistant::citesASource('As the guidance says [2].', 2));
        $this->assertFalse(PilgrimAssistant::citesASource('No markers at all here.', 4));
        $this->assertFalse(PilgrimAssistant::citesASource('Cited [1] against nothing.', 0));
    }

    public function test_a_cited_answer_is_shown_with_its_sources(): void
    {
        $this->configured();
        $article = $this->approvedArticle('Passport placeholder', 'Placeholder body about the passport.');
        $this->modelSays('The approved page covers this [1].');

        $answer = PilgrimAssistant::make()->ask('What about my passport?');

        $this->assertFalse($answer->referred);
        $this->assertStringContainsString('[1]', $answer->text);
        $this->assertSame(1, $answer->sources->count());

        // A citation that cannot be opened and checked is not a citation.
        $this->assertStringContainsString($article->slug, $answer->sources->first()->url);
    }

    // ── What is sent, and what is not ────────────────────────────────────

    /** §9.6: no personal data in prompts. */
    public function test_the_asker_is_never_named_in_the_prompt(): void
    {
        $this->configured();
        $this->approvedArticle('Passport placeholder', 'Placeholder body about the passport.');
        $this->modelSays('The approved page covers this [1].');

        $traveller = Traveller::factory()->create(['full_name' => 'Aishath Placeholder']);

        PilgrimAssistant::make()->ask('What about my passport?', $traveller);

        Http::assertSent(function ($request): bool {
            $body = json_encode($request->data());

            return ! str_contains((string) $body, 'Aishath');
        });
    }

    /** Only the approved passage goes in front of the model. */
    public function test_only_the_approved_passage_is_put_in_front_of_the_model(): void
    {
        $this->configured();
        $this->approvedArticle('Passport placeholder', 'The approved sentence about passports.');

        KnowledgeArticle::factory()->create([
            'title' => ['en' => 'Draft passport page'],
            'body' => ['en' => 'The unapproved sentence about passports.'],
            'status' => KnowledgeArticle::DRAFT,
        ]);

        $this->modelSays('The approved page covers this [1].');

        PilgrimAssistant::make()->ask('What about my passport?');

        Http::assertSent(function ($request): bool {
            $system = (string) ($request->data()['system'] ?? '');

            return str_contains($system, 'The approved sentence about passports.')
                && ! str_contains($system, 'The unapproved sentence about passports.');
        });
    }

    // ── When the provider is unwell ──────────────────────────────────────

    public function test_a_provider_that_fails_produces_a_referral_and_not_an_error(): void
    {
        $this->configured();
        $this->approvedArticle('Passport placeholder', 'Placeholder body about the passport.');

        Http::fake(['api.anthropic.test/*' => Http::response(['error' => 'overloaded'], 529)]);

        $answer = PilgrimAssistant::make()->ask('What about my passport?');

        $this->assertTrue($answer->referred);
        $this->assertStringContainsString('could not be reached', (string) $answer->because);
    }

    public function test_an_unconfigured_provider_reports_itself_as_such(): void
    {
        config(['assistant.anthropic.key' => null]);

        $this->assertFalse((new AnthropicProvider)->isConfigured());
        $this->assertNull((new AnthropicProvider)->complete('system', 'question'));
    }

    // ── The log §9.6 requires ────────────────────────────────────────────

    public function test_every_exchange_is_logged_with_its_reason(): void
    {
        $this->configured();

        PilgrimAssistant::make()->ask('What should I do at the miqat?');

        $exchange = AssistantExchange::sole();

        $this->assertTrue($exchange->referred);
        $this->assertStringContainsString('miqat', $exchange->question);
        $this->assertNotEmpty($exchange->reason);
    }

    public function test_the_log_records_which_passages_the_model_was_allowed_to_read(): void
    {
        $this->configured();
        $this->approvedArticle('Passport placeholder', 'Placeholder body about the passport.');
        $this->modelSays('The approved page covers this [1].');

        PilgrimAssistant::make()->ask('What about my passport?');

        $exchange = AssistantExchange::sole();

        $this->assertFalse($exchange->referred);
        $this->assertStringContainsString('Passport placeholder', $exchange->sourceTitles());
        $this->assertSame('anthropic', $exchange->provider);
    }

    public function test_the_log_is_pruned_and_never_wholly_deleted(): void
    {
        $this->configured();

        AssistantExchange::create([
            'question' => 'old', 'answer' => 'old', 'referred' => true,
            'created_at' => now()->subDays(400), 'updated_at' => now()->subDays(400),
        ]);
        AssistantExchange::create([
            'question' => 'recent', 'answer' => 'recent', 'referred' => true,
        ]);

        $this->artisan('assistant:prune', ['--days' => 180])->assertSuccessful();

        $this->assertSame(1, AssistantExchange::count());
        $this->assertSame('recent', AssistantExchange::sole()->question);

        // A retention of zero would empty the log. It refuses.
        $this->artisan('assistant:prune', ['--days' => 0])->assertFailed();
        $this->assertSame(1, AssistantExchange::count());
    }

    // ── Retrieval ────────────────────────────────────────────────────────

    public function test_common_words_alone_do_not_match_everything(): void
    {
        $this->approvedArticle('Passport placeholder', 'Placeholder body about the passport.');

        $this->assertTrue(Corpus::matching('what is the')->isEmpty());
        $this->assertSame([], Corpus::terms('what is the'));
    }
}
