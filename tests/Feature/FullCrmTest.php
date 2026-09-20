<?php

namespace Tests\Feature;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\Departure;
use App\Models\Enquiry;
use App\Models\Quotation;
use App\Models\User;
use App\Support\CustomerDossier;
use App\Support\Money;
use App\Support\Reengagement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rest of the CRM — §8.1.
 *
 * Phase 3 shipped the minimum that beats a shared inbox. What these hold
 * down is everything between "somebody asked" and "somebody paid":
 *
 * 1. **A quotation is superseded, never edited.** The point of keeping one
 *    is being able to answer "what did we quote them?" a fortnight later,
 *    and editing a sent price destroys exactly that.
 * 2. **Money is integer minor units, never summed across currencies.**
 *    [R-7], and the one mistake in this area that looks right on screen.
 * 3. **The re-engagement list excludes anybody already being spoken to.**
 *    Both exclusions are live reads, so nobody has to remember to take a
 *    person off a list.
 */
class FullCrmTest extends TestCase
{
    use RefreshDatabase;

    private function enquiry(?Customer $customer = null): Enquiry
    {
        return Enquiry::factory()->create([
            'customer_id' => $customer?->getKey(),
        ]);
    }

    // ── Quotations ───────────────────────────────────────────────────────

    public function test_a_quotation_gets_a_readable_reference_of_its_own(): void
    {
        $quotation = Quotation::factory()->create();

        $this->assertMatchesRegularExpression('/^RIH-Q-\d{4}-\d{4}$/', $quotation->reference);
    }

    public function test_the_price_is_integer_minor_units(): void
    {
        $quotation = Quotation::factory()->create(['total_minor' => 5_700_000, 'currency' => 'MVR']);

        $this->assertSame(5_700_000, $quotation->total_minor);
        $this->assertSame(57_000, $quotation->total()->major());
        // The number the customer hears, which is not the number stored.
        $this->assertSame(28_500, $quotation->perPerson()->major());
    }

    public function test_a_party_of_one_is_not_divided_by_zero(): void
    {
        $quotation = Quotation::factory()->create(['party_size' => 0, 'total_minor' => 1_000_000]);

        $this->assertSame(10_000, $quotation->perPerson()->major());
    }

    /**
     * The whole reason to keep a quotation.
     *
     * Editing a price somebody has already been given means the record no
     * longer says what they were told.
     */
    public function test_a_sent_quotation_is_replaced_rather_than_edited(): void
    {
        $original = Quotation::factory()->sent()->create(['total_minor' => 5_700_000]);
        $replacement = Quotation::factory()->create([
            'enquiry_id' => $original->enquiry_id,
            'total_minor' => 5_200_000,
        ]);

        $original->supersedeWith($replacement);

        $original = $original->fresh();

        $this->assertSame(Quotation::SUPERSEDED, $original->status);
        $this->assertSame($replacement->getKey(), $original->superseded_by);
        // Both stay on the record, so a year later somebody can see that
        // the price moved and by how much.
        $this->assertSame(5_700_000, $original->total_minor);
        $this->assertSame(5_200_000, $original->replacement->total_minor);
    }

    public function test_a_quotation_cannot_replace_itself(): void
    {
        $quotation = Quotation::factory()->create();

        $this->expectException(EditorialStandardNotMet::class);

        $quotation->supersedeWith($quotation);
    }

    public function test_only_a_draft_can_be_sent(): void
    {
        $quotation = Quotation::factory()->sent()->create();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('Only a draft can be sent');

        $quotation->markSent();
    }

    public function test_declining_records_why(): void
    {
        $quotation = Quotation::factory()->sent()->create();

        $quotation->decline('Dates did not work for them.');

        $this->assertSame(Quotation::DECLINED, $quotation->fresh()->status);
        $this->assertSame('Dates did not work for them.', $quotation->fresh()->decline_reason);
    }

    public function test_declining_without_a_reason_is_refused(): void
    {
        $quotation = Quotation::factory()->sent()->create();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('Say why');

        $quotation->decline('   ');
    }

    public function test_a_settled_quotation_cannot_be_settled_again(): void
    {
        $quotation = Quotation::factory()->sent()->create();
        $quotation->accept();

        $this->expectException(EditorialStandardNotMet::class);
        $this->expectExceptionMessage('already been settled');

        $quotation->fresh()->decline('Changed their mind.');
    }

    /**
     * Somebody says yes on the phone before anybody has taken a seat.
     * Refusing to record that until the booking exists means one person
     * remembers it.
     */
    public function test_an_acceptance_can_be_recorded_before_the_booking_exists(): void
    {
        $quotation = Quotation::factory()->sent()->create();

        $quotation->accept();

        $this->assertSame(Quotation::ACCEPTED, $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->booking_id);
    }

    /**
     * A quotation with no end date is a price the operator is held to for
     * ever. The expiry is derived, never stored.
     */
    public function test_a_quotation_goes_out_of_date_by_itself(): void
    {
        $quotation = Quotation::factory()->sent()->expiringOn(now()->addDay()->toDateString())->create();

        $this->assertTrue($quotation->isOpen());
        $this->assertFalse($quotation->isExpired());

        $this->travel(3)->days();

        $quotation = $quotation->fresh();

        $this->assertTrue($quotation->isExpired());
        $this->assertFalse($quotation->isOpen());
        $this->assertSame('Out of date', $quotation->statusLabel());
    }

    /** The deal was done while it stood; an accepted offer does not lapse. */
    public function test_an_accepted_quotation_does_not_expire(): void
    {
        $quotation = Quotation::factory()->sent()->expiringOn(now()->addDay()->toDateString())->create();
        $quotation->accept();

        $this->travel(30)->days();

        $this->assertFalse($quotation->fresh()->isExpired());
        $this->assertSame('Accepted', $quotation->fresh()->statusLabel());
    }

    /**
     * Not simply the newest: showing a superseded or declined price as the
     * current one is how somebody gets quoted a number that was withdrawn.
     */
    public function test_the_current_quotation_is_the_one_that_still_stands(): void
    {
        $enquiry = $this->enquiry();

        $old = Quotation::factory()->sent()->create(['enquiry_id' => $enquiry->getKey()]);
        $new = Quotation::factory()->sent()->create(['enquiry_id' => $enquiry->getKey()]);
        $old->supersedeWith($new);

        $this->assertTrue($new->is($enquiry->fresh()->currentQuotation()));

        $new->decline('Too expensive.');

        $this->assertNull($enquiry->fresh()->currentQuotation());
    }

    public function test_every_status_has_a_sentence(): void
    {
        foreach (Quotation::STATUSES as $status) {
            $this->assertNotSame('Unknown', (new Quotation(['status' => $status]))->statusLabel());
        }
    }

    // ── Follow-up tasks ──────────────────────────────────────────────────

    public function test_a_task_belongs_to_whoever_wrote_it_unless_it_is_given_away(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $task = CrmTask::create(['subject' => 'Ring them', 'due_on' => now()->addDay()->toDateString()]);

        $this->assertSame($user->getKey(), $task->owner_id);
        $this->assertSame($user->getKey(), $task->created_by);
    }

    /** One next action was never enough. */
    public function test_an_enquiry_can_carry_more_than_one_follow_up(): void
    {
        $enquiry = $this->enquiry();

        CrmTask::factory()->count(2)->create([
            'about_type' => Enquiry::class,
            'about_id' => $enquiry->getKey(),
        ]);

        $this->assertCount(2, $enquiry->fresh()->tasks);
    }

    /** "Ring them about their passport" is not a lead. */
    public function test_a_task_can_hang_off_a_customer_or_a_booking(): void
    {
        $customer = Customer::factory()->create();

        CrmTask::factory()->create(['about_type' => Customer::class, 'about_id' => $customer->getKey()]);

        $this->assertCount(1, $customer->fresh()->tasks);
        $this->assertSame($customer->name, $customer->fresh()->tasks->first()->aboutLabel());
    }

    public function test_done_records_who_and_when_rather_than_a_flag(): void
    {
        $user = User::factory()->create();
        $task = CrmTask::factory()->create();

        $task->complete($user);

        $task = $task->fresh();

        $this->assertTrue($task->isDone());
        $this->assertSame($user->getKey(), $task->done_by);
        $this->assertNotNull($task->done_at);
    }

    public function test_completing_twice_does_not_move_the_date(): void
    {
        $task = CrmTask::factory()->create();
        $task->complete(User::factory()->create());

        $first = $task->fresh()->done_at;

        $this->travel(2)->hours();
        $task->fresh()->complete(User::factory()->create());

        $this->assertSame($first->toDateTimeString(), $task->fresh()->done_at->toDateTimeString());
    }

    public function test_overdue_counts_only_what_is_not_done(): void
    {
        CrmTask::factory()->dueOn(now()->subDays(3)->toDateString())->create();
        CrmTask::factory()->dueOn(now()->subDays(3)->toDateString())->done()->create();
        CrmTask::factory()->dueOn(now()->addDays(3)->toDateString())->create();

        $this->assertSame(1, CrmTask::overdue()->count());
        $this->assertSame(2, CrmTask::open()->count());
    }

    /** In words, not a date the reader has to subtract from today. */
    public function test_the_when_label_says_what_to_do_about_it(): void
    {
        $this->assertSame('Today', CrmTask::factory()->dueOn(now()->toDateString())->create()->whenLabel());
        $this->assertStringStartsWith(
            'Overdue',
            CrmTask::factory()->dueOn(now()->subDay()->toDateString())->create()->whenLabel(),
        );
        $this->assertSame('Done', CrmTask::factory()->done()->create()->whenLabel());
    }

    // ── Tags and referrals ───────────────────────────────────────────────

    /** "Ramadan" and "ramadan" typed by two people is one fact. */
    public function test_a_tag_is_folded_to_lower_case(): void
    {
        $customer = Customer::factory()->create();

        CustomerTag::create(['customer_id' => $customer->getKey(), 'tag' => '  Prefers Ramadan ']);

        $this->assertSame('prefers ramadan', $customer->fresh()->tags->first()->tag);
    }

    public function test_the_same_tag_cannot_be_added_twice(): void
    {
        $customer = Customer::factory()->create();

        CustomerTag::create(['customer_id' => $customer->getKey(), 'tag' => 'ramadan']);

        $this->expectException(UniqueConstraintViolationException::class);

        CustomerTag::create(['customer_id' => $customer->getKey(), 'tag' => 'Ramadan']);
    }

    /** A person we can find, not a name in a box. */
    public function test_a_referral_points_at_a_customer_we_already_have(): void
    {
        $referrer = Customer::factory()->create(['name' => 'The person who sent them']);
        $referred = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);

        $this->assertSame('The person who sent them', $referred->fresh()->referrer->name);
        $this->assertCount(1, $referrer->fresh()->referrals);
    }

    // ── The customer 360 ─────────────────────────────────────────────────

    private function travelledCustomer(int $monthsAgo): Customer
    {
        $customer = Customer::factory()->create();

        $departure = Departure::factory()->withSeats(10)->create([
            'date_start' => now()->subMonths($monthsAgo)->startOfDay(),
            'date_end' => now()->subMonths($monthsAgo)->addDays(14)->startOfDay(),
        ]);

        Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 2_850_000,
        ]);

        return $customer->fresh();
    }

    public function test_the_dossier_counts_journeys_that_happened(): void
    {
        $customer = $this->travelledCustomer(6);

        // A future booking is not a journey taken.
        Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->addMonths(2)->startOfDay(),
                'date_end' => now()->addMonths(2)->addDays(14)->startOfDay(),
            ])->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 1_000_000,
        ]);

        $dossier = CustomerDossier::build($customer->fresh());

        $this->assertCount(2, $dossier->bookings);
        $this->assertSame(1, $dossier->journeysTaken());
        $this->assertSame(6, $dossier->monthsSinceLastJourney());
    }

    /**
     * [R-7]: never summed across currencies.
     *
     * The one mistake in this area that looks right on screen — a total
     * that silently adds rufiyaa to dollars.
     */
    public function test_what_is_owed_is_kept_apart_by_currency(): void
    {
        $customer = Customer::factory()->create();
        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addMonths(2)->startOfDay(),
            'date_end' => now()->addMonths(2)->addDays(14)->startOfDay(),
        ]);

        foreach ([['MVR', 2_850_000], ['USD', 150_000]] as [$currency, $minor]) {
            Booking::factory()->create([
                'customer_id' => $customer->getKey(),
                'departure_id' => $departure->getKey(),
                'status' => Booking::CONFIRMED,
                'seats' => 1,
                'currency' => $currency,
                'total_minor' => $minor,
            ]);
        }

        $owed = CustomerDossier::build($customer->fresh())->outstanding();

        $this->assertCount(2, $owed);
        $this->assertSame(2_850_000, $owed->get('MVR')->minor);
        $this->assertSame(150_000, $owed->get('USD')->minor);
    }

    public function test_the_dossier_gathers_open_tasks_from_every_direction(): void
    {
        $customer = $this->travelledCustomer(3);
        $enquiry = $this->enquiry($customer);

        CrmTask::factory()->create(['about_type' => Customer::class, 'about_id' => $customer->getKey()]);
        CrmTask::factory()->create(['about_type' => Enquiry::class, 'about_id' => $enquiry->getKey()]);
        CrmTask::factory()->create([
            'about_type' => Booking::class,
            'about_id' => $customer->bookings()->first()->getKey(),
        ]);
        CrmTask::factory()->done()->create(['about_type' => Customer::class, 'about_id' => $customer->getKey()]);

        // Three open, and the finished one is not work.
        $this->assertCount(3, CustomerDossier::build($customer->fresh())->openTasks);
    }

    /**
     * The two numbers on the customer page have to agree.
     *
     * They did not, once: it said "has not travelled with us yet" directly
     * above "last travelled 14 months ago", because one counted confirmed
     * bookings and the other counted every live one. A screenshot caught
     * it; nothing in this file did.
     */
    public function test_a_draft_booking_is_not_a_journey_by_either_count(): void
    {
        $customer = Customer::factory()->create();

        Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->subMonths(14)->startOfDay(),
                'date_end' => now()->subMonths(14)->addDays(14)->startOfDay(),
            ])->getKey(),
            'status' => Booking::DRAFT,
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 1_000_000,
        ]);

        $dossier = CustomerDossier::build($customer->fresh());

        $this->assertSame(0, $dossier->journeysTaken());
        $this->assertNull($dossier->monthsSinceLastJourney());
        // And the re-engagement list does not ring somebody who never went.
        $this->assertCount(0, Reengagement::candidates());
    }

    public function test_a_customer_who_never_travelled_says_so_rather_than_zero_months(): void
    {
        $dossier = CustomerDossier::build(Customer::factory()->create());

        $this->assertSame(0, $dossier->journeysTaken());
        // Null, not 0. "Never" and "this month" are different phone calls.
        $this->assertNull($dossier->monthsSinceLastJourney());
    }

    // ── Re-engagement ────────────────────────────────────────────────────

    public function test_somebody_quiet_for_long_enough_is_worth_a_call(): void
    {
        $quiet = $this->travelledCustomer(18);
        $this->travelledCustomer(2);

        $candidates = Reengagement::candidates(months: 12);

        $this->assertCount(1, $candidates);
        $this->assertTrue($quiet->is($candidates->first()['customer']));
        $this->assertSame(18, $candidates->first()['months']);
    }

    /** Ringing somebody as a cold lead while a colleague is mid-conversation. */
    public function test_somebody_with_an_open_enquiry_is_left_alone(): void
    {
        $customer = $this->travelledCustomer(18);

        $this->assertCount(1, Reengagement::candidates());

        Enquiry::factory()->create(['customer_id' => $customer->getKey(), 'status' => Enquiry::WORKING]);

        $this->assertCount(0, Reengagement::candidates());
    }

    /** They have not gone quiet — they are about to fly. */
    public function test_somebody_about_to_travel_is_left_alone(): void
    {
        $customer = $this->travelledCustomer(18);

        Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->addMonth()->startOfDay(),
                'date_end' => now()->addMonth()->addDays(14)->startOfDay(),
            ])->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 1_000_000,
        ]);

        $this->assertCount(0, Reengagement::candidates());
    }

    public function test_somebody_who_never_travelled_is_not_a_re_engagement(): void
    {
        Customer::factory()->create();

        $this->assertCount(0, Reengagement::candidates());
    }

    public function test_nothing_crm_is_seeded(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertSame(0, Quotation::count());
        $this->assertSame(0, CrmTask::count());
        $this->assertSame(0, CustomerTag::count());
    }
}
