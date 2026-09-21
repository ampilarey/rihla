<?php

namespace Tests\Feature;

use App\Filament\Pages\Drafting;
use App\Models\AssistantExchange;
use App\Models\User;
use App\Services\Assistant\StaffDrafter;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The staff drafting assistant — §9.6's second of two.
 *
 * §9.6: "quotations, itinerary text, announcement translation (en/dv/ar)
 * **with human review before send**."
 *
 * The translation half is deliberately not built, and that refusal has a
 * test of its own rather than being a silent absence. `AGENTS.md` records
 * what machine-generated Dhivehi has already cost this site; a button
 * producing Dhivehi nobody in the office can check is the same mistake
 * with a nicer interface.
 */
class StaffDrafterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
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

    // ── The refusal that is a decision, not a gap ────────────────────────

    /**
     * It does not translate, and the reason is on the screen.
     *
     * A missing feature somebody cannot see the reason for is a feature
     * somebody adds back next quarter.
     */
    public function test_it_declines_to_translate_and_says_why(): void
    {
        $this->assertTrue(StaffDrafter::refusesTranslation());

        $why = StaffDrafter::whyNoTranslation();

        $this->assertStringContainsString('Dhivehi', $why);
        $this->assertStringContainsString('found by a reader', $why);

        $this->assertNotContains('translate', StaffDrafter::KINDS);
    }

    public function test_the_page_carries_the_reason_there_is_no_dhivehi(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(Drafting::getUrl())
            ->assertSuccessful()
            ->assertSee('Why there is no Dhivehi here')
            ->assertSee('This will not translate');
    }

    /** And the prompt tells the model the same thing. */
    public function test_the_prompt_forbids_translation_and_rulings(): void
    {
        $this->configured();
        $this->modelSays('A placeholder draft.');

        StaffDrafter::make()->draft(StaffDrafter::ENQUIRY_REPLY, 'Party of four, departing in Ramadan.');

        Http::assertSent(function ($request): bool {
            $system = (string) ($request->data()['system'] ?? '');

            return str_contains($system, 'Do not translate into Dhivehi')
                && str_contains($system, 'Never give a religious ruling')
                && str_contains($system, 'Invent nothing');
        });
    }

    // ── Unconfigured is a state, not a failure ───────────────────────────

    public function test_with_no_key_it_produces_nothing_and_names_the_gap(): void
    {
        config(['assistant.enabled' => true, 'assistant.anthropic.key' => null]);

        $drafter = StaffDrafter::make();

        $this->assertNull($drafter->draft(StaffDrafter::ENQUIRY_REPLY, 'Party of four.'));
        $this->assertStringContainsString('API key', (string) $drafter->whyNotAvailable());
        Http::assertNothingSent();
    }

    public function test_switched_off_it_produces_nothing(): void
    {
        config(['assistant.enabled' => false]);

        $this->assertNull(StaffDrafter::make()->draft(StaffDrafter::ITINERARY, 'Arrive Jeddah, transfer, check in.'));
        Http::assertNothingSent();
    }

    /**
     * The page says it is not set up rather than offering a dead button.
     */
    public function test_the_page_says_there_is_no_assistant_behind_it(): void
    {
        $this->actingAs($this->staff(Access::BOOKING_STAFF))
            ->get(Drafting::getUrl())
            ->assertSuccessful()
            ->assertSee('There is no assistant behind this page');
    }

    // ── What it does when it is configured ───────────────────────────────

    public function test_it_returns_a_draft_for_each_kind_it_knows(): void
    {
        $this->configured();
        $this->modelSays('A placeholder draft.');

        foreach (StaffDrafter::KINDS as $kind) {
            $this->assertSame(
                'A placeholder draft.',
                StaffDrafter::make()->draft($kind, 'Some notes to write from.'),
                "[{$kind}] produced nothing.",
            );
        }
    }

    public function test_an_unknown_kind_and_empty_notes_produce_nothing(): void
    {
        $this->configured();
        $this->modelSays('A placeholder draft.');

        $this->assertNull(StaffDrafter::make()->draft('translate_to_dhivehi', 'Some notes.'));
        $this->assertNull(StaffDrafter::make()->draft(StaffDrafter::ENQUIRY_REPLY, '   '));
        Http::assertNothingSent();
    }

    public function test_a_provider_that_fails_produces_null_rather_than_an_apology(): void
    {
        $this->configured();

        Http::fake(['api.anthropic.test/*' => Http::response(['error' => 'overloaded'], 529)]);

        // Null, not a placeholder sentence: a member of staff who sees an
        // empty box writes it themselves; one who sees "I could not
        // generate this" may paste it.
        $this->assertNull(StaffDrafter::make()->draft(StaffDrafter::ANNOUNCEMENT, 'Coach leaves at six.'));
    }

    // ── The log §9.6 requires ────────────────────────────────────────────

    public function test_every_draft_is_logged_with_what_it_was_asked_for(): void
    {
        $this->configured();
        $this->modelSays('A placeholder draft.');

        StaffDrafter::make()->draft(StaffDrafter::ANNOUNCEMENT, 'Coach leaves at six from the hotel lobby.');

        $exchange = AssistantExchange::sole();

        $this->assertStringContainsString('[announcement]', $exchange->question);
        $this->assertStringContainsString('Coach leaves at six', $exchange->question);
        $this->assertSame('A placeholder draft.', $exchange->answer);

        // Nobody asked this — a member of staff did — so no traveller is
        // attached to it.
        $this->assertNull($exchange->traveller_id);
    }

    // ── Who may use it ───────────────────────────────────────────────────

    public function test_the_roles_that_write_to_customers_may_draft(): void
    {
        foreach ([Access::BOOKING_STAFF, Access::CONTENT_MANAGER, Access::OPERATIONS_MANAGER] as $role) {
            $this->assertTrue($this->staff($role)->can('draft.use'), "[{$role}] cannot draft.");
        }
    }

    /**
     * `draft.use` is a verb about having a machine write, and nothing else.
     *
     * It never stands in for the permission on the thing being drafted: a
     * drafted announcement still needs somebody who may create one.
     */
    public function test_drafting_is_not_permission_to_send_anything(): void
    {
        $drafter = $this->staff(Access::CONTENT_MANAGER);

        $this->assertTrue($drafter->can('draft.use'));
        $this->assertFalse($drafter->can('announcement.publish'));
    }

    public function test_a_role_without_it_cannot_open_the_page(): void
    {
        foreach ([Access::TOUR_LEADER, Access::SCHOLAR, Access::FINANCE] as $role) {
            $staff = $this->staff($role);

            $this->assertFalse($staff->can('draft.use'), "[{$role}] can draft.");
            $this->actingAs($staff)->get(Drafting::getUrl())->assertForbidden();
        }
    }

    // ── The screen ───────────────────────────────────────────────────────

    public function test_the_page_shows_a_draft_with_the_label_on_it(): void
    {
        $this->configured();
        $this->modelSays('A placeholder draft for the screen.');

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(Drafting::class)
            ->set('kind', StaffDrafter::ENQUIRY_REPLY)
            ->set('notes', 'Party of four, departing in Ramadan, deposit not yet paid.')
            ->call('draft')
            ->assertSet('result', 'A placeholder draft for the screen.')
            ->assertSee('AI-assisted — read it before you use it');
    }

    /** §9.6: no personal data in prompts, said where the notes are typed. */
    public function test_the_page_warns_against_putting_customer_details_in_the_notes(): void
    {
        $this->actingAs($this->staff(Access::BOOKING_STAFF))
            ->get(Drafting::getUrl())
            ->assertSee('No customer details in the notes')
            ->assertSee('passport number');
    }
}
