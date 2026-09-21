<?php

namespace Tests\Feature;

use App\Filament\Pages\WhoSendsUsPeople;
use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Models\User;
use App\Support\Access;
use App\Support\ReferralCredit;
use App\Support\Referrals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who sends Rihla people — the other half of §8.1's referral tracking.
 *
 * `Customer::referrer()` has said since Phase 5.5 that the point of
 * tracking a referral is "to be able to thank the person who made it", and
 * nothing delivered that.
 *
 * The thing these hold down is the claim the screen does **not** make. A
 * thank-you is a telephone call; nothing here can see one. What it reports
 * is whether anybody wrote a follow-up down, and conflating the two would
 * turn "nobody has written anything" into "nobody thanked them" — a
 * reproach to the office built out of a missing row.
 */
class ReferralsTest extends TestCase
{
    use RefreshDatabase;

    private function flown(int $daysAgo = 60): Departure
    {
        return Departure::factory()->withSeats(40)->create([
            'package_id' => Package::factory()->create()->getKey(),
            'date_start' => now()->subDays($daysAgo)->startOfDay(),
            'date_end' => now()->subDays($daysAgo - 10)->startOfDay(),
        ]);
    }

    private function upcoming(int $daysAhead = 60): Departure
    {
        return Departure::factory()->withSeats(40)->create([
            'package_id' => Package::factory()->create()->getKey(),
            'date_start' => now()->addDays($daysAhead)->startOfDay(),
            'date_end' => now()->addDays($daysAhead + 10)->startOfDay(),
        ]);
    }

    private function travelled(Customer $customer, Departure $departure, int $seats = 2, string $status = Booking::COMPLETED): Booking
    {
        return Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => $status,
            'seats' => $seats,
            'total_minor' => 1_000_000,
        ]);
    }

    private function noteAbout(Customer $customer, string $when): CrmTask
    {
        $task = CrmTask::create([
            'about_type' => Customer::class,
            'about_id' => $customer->getKey(),
            'subject' => 'Ring them',
            'due_on' => now()->toDateString(),
        ]);

        $task->forceFill(['created_at' => $when])->save();

        return $task;
    }

    private function credit(Customer $referrer): ?ReferralCredit
    {
        return Referrals::build()->first(
            fn (ReferralCredit $c): bool => $c->referrer->getKey() === $referrer->getKey(),
        );
    }

    // ── Nothing to show ──────────────────────────────────────────────────

    public function test_with_no_referrals_recorded_there_is_nothing_to_report(): void
    {
        Customer::factory()->count(3)->create();

        $this->assertTrue(Referrals::build()->isEmpty());
    }

    /** And the page says why the list is empty rather than looking broken. */
    public function test_the_empty_page_names_the_field_that_fills_it(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->get(WhoSendsUsPeople::getUrl())
            ->assertSuccessful()
            ->assertSee('No customer names another as their referrer')
            ->assertSee('Referred by');
    }

    // ── The counting ─────────────────────────────────────────────────────

    public function test_it_counts_who_was_referred_and_how_many_travelled(): void
    {
        $referrer = Customer::factory()->create(['name' => 'Placeholder Referrer']);

        $flew = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);
        $this->travelled($flew, $this->flown(), seats: 3);

        Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);

        $credit = $this->credit($referrer);

        $this->assertNotNull($credit);
        $this->assertSame(2, $credit->referred);
        $this->assertSame(1, $credit->travelled);
        $this->assertSame(3, $credit->seats);
        $this->assertStringContainsString('2 people, 1 of whom travelled', $credit->spoken());
        $this->assertStringContainsString('3 seats in all', $credit->spoken());
    }

    /**
     * A booking that has not flown is a favour in progress.
     *
     * Counting it as travelled would have the office thanking somebody for
     * a journey that has not happened, which is worse than saying nothing.
     */
    public function test_a_referral_who_has_not_flown_yet_is_not_counted_as_travelled(): void
    {
        $referrer = Customer::factory()->create();

        $booked = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);
        $this->travelled($booked, $this->upcoming(), status: Booking::CONFIRMED);

        $credit = $this->credit($referrer);

        $this->assertSame(1, $credit->referred);
        $this->assertSame(0, $credit->travelled);
        $this->assertNull($credit->lastArrival);
        $this->assertFalse($credit->isUnacknowledged());
        $this->assertStringContainsString('none of whom has travelled yet', $credit->spoken());
    }

    public function test_a_cancelled_booking_is_not_a_journey(): void
    {
        $referrer = Customer::factory()->create();

        $cancelled = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);
        $this->travelled($cancelled, $this->flown(), status: Booking::CANCELLED);

        $this->assertSame(0, $this->credit($referrer)->travelled);
    }

    // ── The claim the screen does not make ───────────────────────────────

    /**
     * "Nothing written down" is what it reports, and the words matter.
     *
     * Reporting it as "not thanked" would be a reproach to the office
     * manufactured out of a missing row.
     */
    public function test_a_referral_with_no_follow_up_recorded_is_flagged(): void
    {
        $referrer = Customer::factory()->create();

        $flew = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);
        $this->travelled($flew, $this->flown());

        $credit = $this->credit($referrer);

        $this->assertTrue($credit->isUnacknowledged());
        $this->assertNull($credit->lastNoted);
        $this->assertSame('warning', $credit->tone());
    }

    public function test_a_follow_up_recorded_after_the_arrival_clears_it(): void
    {
        $referrer = Customer::factory()->create();

        $flew = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);
        $this->travelled($flew, $this->flown(daysAgo: 60));

        $this->noteAbout($referrer, now()->subDays(20)->toDateTimeString());

        $this->assertFalse($this->credit($referrer)->isUnacknowledged());
    }

    /**
     * A note from before the referral flew does not cover it.
     *
     * Otherwise the first conversation anybody ever had with a customer
     * would silently discharge every referral they made afterwards.
     */
    public function test_a_follow_up_from_before_the_arrival_does_not_count(): void
    {
        $referrer = Customer::factory()->create();

        $flew = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);
        $this->travelled($flew, $this->flown(daysAgo: 30));

        $this->noteAbout($referrer, now()->subDays(200)->toDateTimeString());

        $credit = $this->credit($referrer);

        $this->assertTrue($credit->isUnacknowledged());
        $this->assertNotNull($credit->lastNoted);
    }

    /** The page repeats the distinction rather than assuming it is read. */
    public function test_the_page_says_it_cannot_tell_you_who_has_been_thanked(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->get(WhoSendsUsPeople::getUrl())
            ->assertSuccessful()
            ->assertSee('It cannot tell you who has been thanked')
            ->assertSee('A thank-you is a telephone call');
    }

    public function test_the_page_lists_a_referrer_with_what_they_sent(): void
    {
        $referrer = Customer::factory()->create(['name' => 'Placeholder Referrer']);

        $flew = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);
        $this->travelled($flew, $this->flown(), seats: 4);

        $this->actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->get(WhoSendsUsPeople::getUrl())
            ->assertSuccessful()
            ->assertSee('Placeholder Referrer')
            ->assertSee('Nothing written down since their last referral flew');
    }

    // ── Ordering ─────────────────────────────────────────────────────────

    /**
     * What to do next comes before how big it was.
     *
     * A list ordered by size puts the biggest referrer on top every time,
     * including on the days when somebody has already dealt with them.
     */
    public function test_an_unacknowledged_referral_outranks_a_larger_settled_one(): void
    {
        $big = Customer::factory()->create(['name' => 'Big Placeholder']);
        $bigsFriend = Customer::factory()->create(['referred_by_customer_id' => $big->getKey()]);
        $this->travelled($bigsFriend, $this->flown(daysAgo: 90), seats: 10);
        $this->noteAbout($big, now()->subDays(10)->toDateTimeString());

        $small = Customer::factory()->create(['name' => 'Small Placeholder']);
        $smallsFriend = Customer::factory()->create(['referred_by_customer_id' => $small->getKey()]);
        $this->travelled($smallsFriend, $this->flown(daysAgo: 30), seats: 1);

        $order = Referrals::build()->map(fn (ReferralCredit $c): string => $c->referrer->name)->all();

        $this->assertSame(['Small Placeholder', 'Big Placeholder'], $order);
        $this->assertSame(1, Referrals::unacknowledged()->count());
    }

    /**
     * Within a group, the bigger favour comes first — the secondary sort.
     *
     * This is the test that was missing when the ordering was written with
     * `sortBy([fn, fn])`. In that multi-sort form Laravel treats each
     * callable as a two-argument *comparator*, so a one-argument accessor
     * silently becomes a comparator that returns 0 or 1 and never -1: the
     * secondary sort does nothing at all. The first version of this screen
     * shipped that way and listed a nought-seat referrer above an
     * eight-seat one, and every assertion still passed because they only
     * ever checked the primary key.
     */
    public function test_within_a_settled_group_the_bigger_favour_comes_first(): void
    {
        foreach ([['Eight Placeholder', 8], ['One Placeholder', 1], ['Four Placeholder', 4]] as [$name, $seats]) {
            $referrer = Customer::factory()->create(['name' => $name]);
            $friend = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);
            $this->travelled($friend, $this->flown(daysAgo: 90), seats: $seats);

            // Everybody settled, so only the secondary key can order them.
            $this->noteAbout($referrer, now()->subDays(10)->toDateTimeString());
        }

        $this->assertSame(
            ['Eight Placeholder', 'Four Placeholder', 'One Placeholder'],
            Referrals::build()->map(fn (ReferralCredit $c): string => $c->referrer->name)->all(),
        );
    }

    // ── The dossier ──────────────────────────────────────────────────────

    public function test_the_customer_page_shows_both_directions_of_a_referral(): void
    {
        $referrer = Customer::factory()->create(['name' => 'Placeholder Referrer']);
        $referred = Customer::factory()->create([
            'name' => 'Placeholder Referred',
            'referred_by_customer_id' => $referrer->getKey(),
        ]);
        $this->travelled($referred, $this->flown());

        $staff = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);

        // On the person who was sent: who sent them.
        $this->actingAs($staff)
            ->get(route('filament.staff.resources.customers.view', $referred))
            ->assertSuccessful()
            ->assertSee('Sent to us by Placeholder Referrer');

        // On the person who sent them: what it came to.
        $this->actingAs($staff)
            ->get(route('filament.staff.resources.customers.view', $referrer))
            ->assertSuccessful()
            ->assertSee('They have sent us 1 person');
    }

    /** Somebody who neither referred nor was referred gets no section. */
    public function test_a_customer_with_no_referrals_gets_no_referral_section(): void
    {
        $plain = Customer::factory()->create(['name' => 'Placeholder Plain']);

        $this->actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))
            ->get(route('filament.staff.resources.customers.view', $plain))
            ->assertSuccessful()
            ->assertDontSee('They have sent us');
    }

    // ── Who may open it ──────────────────────────────────────────────────

    public function test_it_is_gated_on_reading_customers_and_not_a_verb_of_its_own(): void
    {
        $this->assertNotContains('referral.view', Access::PERMISSIONS);

        $leader = User::factory()->create()->assignRole(Access::TOUR_LEADER);

        $this->assertFalse($leader->can('customer.viewAny'));
        $this->actingAs($leader)->get(WhoSendsUsPeople::getUrl())->assertForbidden();
    }
}
