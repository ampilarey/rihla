<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\EnquiryNote;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enquiries — §8.1's minimal CRM.
 *
 * The plan names what the first version is: "every enquiry becomes a tracked
 * lead with an owner and a next action — that alone beats a shared inbox."
 * Most of what is tested here is the finding of enquiries that have neither.
 */
class EnquiryTest extends TestCase
{
    use RefreshDatabase;

    // ── The form ─────────────────────────────────────────────────────────

    public function test_the_contact_page_offers_the_form(): void
    {
        $this->get('/en/contact')
            ->assertOk()
            ->assertSee('Or send us a message')
            // Under WhatsApp, not instead of it.
            ->assertSee('Message on WhatsApp');
    }

    public function test_a_message_becomes_a_tracked_lead(): void
    {
        $package = Package::factory()->create(['is_published' => true]);

        $this->post('/en/contact', [
            'name' => 'Ibrahim Waheed',
            'phone' => '7712345',
            'message' => 'Is there anything in Ramadan?',
            'package_id' => $package->getKey(),
            'party_size' => 4,
        ])->assertRedirect('/en/contact');

        $enquiry = Enquiry::sole();

        $this->assertSame('Ibrahim Waheed', $enquiry->name);
        $this->assertSame(Enquiry::WEB, $enquiry->source);
        $this->assertSame(Enquiry::NEW, $enquiry->status);
        $this->assertSame(4, $enquiry->party_size);
        $this->assertSame($package->getKey(), $enquiry->package_id);
        $this->assertMatchesRegularExpression('/^RIH-E-\d{4}-\d{4}$/', (string) $enquiry->reference);
    }

    /** The sender is told what happened, on the page they land on. */
    public function test_the_page_says_thank_you(): void
    {
        $this->post('/en/contact', ['name' => 'Ibrahim Waheed', 'phone' => '7712345']);

        $this->followingRedirects()
            ->post('/en/contact', ['name' => 'Aminath Zahira', 'phone' => '7798765'])
            ->assertOk()
            ->assertSee('we have your message');
    }

    /**
     * A number or an email, not both.
     *
     * Demanding an email as well loses the enquiries of people who do not
     * use one, which in this market is a lot of them.
     */
    public function test_either_a_phone_or_an_email_will_do(): void
    {
        $this->post('/en/contact', ['name' => 'Ibrahim Waheed', 'phone' => '7712345'])
            ->assertSessionHasNoErrors();

        $this->post('/en/contact', ['name' => 'Aminath Zahira', 'email' => 'a@example.mv'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Enquiry::count());
    }

    public function test_neither_a_phone_nor_an_email_is_refused(): void
    {
        $this->post('/en/contact', ['name' => 'Ibrahim Waheed'])
            ->assertSessionHasErrors(['phone', 'email']);

        $this->assertSame(0, Enquiry::count());
    }

    /** Pressing send twice must not make two leads for two people to work. */
    public function test_sending_twice_records_on_the_first_enquiry(): void
    {
        $payload = ['name' => 'Ibrahim Waheed', 'phone' => '7712345', 'message' => 'First'];

        $this->post('/en/contact', $payload);
        // The same number, written the other way — the import's
        // normalisation, reused.
        $this->post('/en/contact', ['name' => 'Ibrahim Waheed', 'phone' => '+960 771 2345', 'message' => 'Second']);

        $this->assertSame(1, Enquiry::count());
        $this->assertStringContainsString('Second', (string) Enquiry::sole()->notes()->latest('id')->first()->body);
    }

    /** A form that says "you already sent this" reveals who is on file. */
    public function test_a_repeat_gets_the_same_answer_as_a_first_message(): void
    {
        $this->post('/en/contact', ['name' => 'Ibrahim Waheed', 'phone' => '7712345']);

        $this->followingRedirects()
            ->post('/en/contact', ['name' => 'Ibrahim Waheed', 'phone' => '7712345'])
            ->assertOk()
            ->assertSee('we have your message')
            ->assertDontSee('already');
    }

    public function test_a_filled_honeypot_is_thanked_and_ignored(): void
    {
        $this->post('/en/contact', [
            'name' => 'Ibrahim Waheed',
            'phone' => '7712345',
            'website' => 'http://spam.example',
        ])->assertRedirect('/en/contact');

        $this->assertSame(0, Enquiry::count());
    }

    public function test_an_unpublished_package_is_not_offered(): void
    {
        Package::factory()->create(['is_published' => false, 'title' => ['en' => 'Secret Departure']]);

        $this->get('/en/contact')->assertOk()->assertDontSee('Secret Departure');
    }

    // ── Adrift: the list this exists to empty ────────────────────────────

    /**
     * An enquiry with no owner or no next action is the message that sits
     * unanswered in a shared inbox for four days.
     */
    public function test_an_enquiry_with_no_owner_is_adrift(): void
    {
        $enquiry = Enquiry::factory()->create();

        $this->assertTrue($enquiry->isAdrift());
        $this->assertSame(1, Enquiry::adrift()->count());
    }

    public function test_an_owner_alone_is_not_enough(): void
    {
        $enquiry = Enquiry::factory()->working()->create(['assigned_to' => $this->user()->getKey()]);

        $this->assertTrue($enquiry->isAdrift(), 'An owner with nothing planned is still adrift.');
    }

    public function test_an_owner_and_a_next_action_is_not_adrift(): void
    {
        $enquiry = Enquiry::factory()->working()->create([
            'assigned_to' => $this->user()->getKey(),
            'next_action' => 'Call back with the Shawwal price',
            'next_action_at' => now()->addDay(),
        ]);

        $this->assertFalse($enquiry->isAdrift());
        $this->assertSame(0, Enquiry::adrift()->count());
    }

    public function test_a_promise_whose_date_has_passed_is_overdue(): void
    {
        $enquiry = Enquiry::factory()->working()->create([
            'assigned_to' => $this->user()->getKey(),
            'next_action' => 'Call back',
            'next_action_at' => now()->subDay(),
        ]);

        $this->assertTrue($enquiry->isOverdue());
        $this->assertSame(1, Enquiry::overdue()->count());
    }

    /** A closed enquiry is not work, however old its next action is. */
    public function test_a_closed_enquiry_is_neither_adrift_nor_overdue(): void
    {
        $enquiry = Enquiry::factory()->create([
            'status' => Enquiry::LOST,
            'next_action_at' => now()->subMonth(),
        ]);

        $this->assertFalse($enquiry->isAdrift());
        $this->assertFalse($enquiry->isOverdue());
        $this->assertSame(0, Enquiry::adrift()->count());
        $this->assertSame(0, Enquiry::overdue()->count());
    }

    // ── History ──────────────────────────────────────────────────────────

    public function test_the_history_is_one_list_in_order(): void
    {
        $enquiry = Enquiry::factory()->create();

        $enquiry->record('Rang, no answer.');
        $enquiry->record('Given to Aishath', EnquiryNote::STATUS);

        $notes = $enquiry->fresh()->notes;

        $this->assertCount(2, $notes);
        $this->assertSame('Rang, no answer.', $notes->first()->body);
        $this->assertSame(EnquiryNote::STATUS, $notes->last()->type);
    }

    // ── Becoming a customer ──────────────────────────────────────────────

    /** Matched the way the import matches, so nobody has to notice. */
    public function test_an_existing_customer_is_found_by_a_differently_written_number(): void
    {
        $customer = Customer::factory()->create(['phone' => '+960 771 2345']);
        $enquiry = Enquiry::factory()->create(['phone' => '7712345']);

        $this->assertSame($customer->getKey(), $enquiry->possibleCustomers()->first()?->getKey());
    }

    public function test_an_enquiry_with_no_number_matches_nobody(): void
    {
        Customer::factory()->create(['phone' => '7712345']);
        $enquiry = Enquiry::factory()->create(['phone' => null]);

        $this->assertTrue($enquiry->possibleCustomers()->isEmpty());
    }

    private function user(): User
    {
        return User::factory()->create();
    }
}
