<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Models\User;
use App\Support\Personalisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Personalisation for returning users — §4.2, and the whole of it.
 *
 * What these hold down is mostly the **absence**: there is exactly one
 * input, a signed-in customer with bookings. No cookie, no fingerprint, no
 * behavioural profile, and no "people like you also viewed".
 *
 * That is a decision rather than an oversight, and it is the same finding
 * §10.5's dashboard already records — nothing here logs a visit. Building
 * the visit log in order to recommend things from it would be building the
 * surveillance first and asking whether anybody wanted it afterwards.
 */
class PersonalisationTest extends TestCase
{
    use RefreshDatabase;

    private function departure(int $daysFromNow): Departure
    {
        return Departure::factory()->withSeats(30)->create([
            'package_id' => Package::factory()->create()->getKey(),
            'date_start' => now()->addDays($daysFromNow)->startOfDay(),
            'date_end' => now()->addDays($daysFromNow + 10)->startOfDay(),
            'is_published' => true,
        ]);
    }

    private function signedInCustomer(string $name = 'Placeholder Customer'): Customer
    {
        $user = User::factory()->create();

        return Customer::factory()->create(['name' => $name, 'user_id' => $user->getKey()]);
    }

    private function travelled(Customer $customer, Departure $departure, string $status = Booking::COMPLETED): Booking
    {
        return Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => $status,
            'seats' => 1,
            'total_minor' => 1_000_000,
        ]);
    }

    // ── Nothing for somebody who has not told us who they are ────────────

    public function test_an_anonymous_visitor_gets_nothing_personal(): void
    {
        $this->departure(40);

        $personal = Personalisation::for(null);

        $this->assertFalse($personal->hasSomethingToSay());
        $this->assertNull($personal->greeting());
    }

    public function test_a_signed_in_user_who_is_not_a_customer_gets_nothing(): void
    {
        $personal = Personalisation::for(User::factory()->create());

        $this->assertFalse($personal->hasSomethingToSay());
        $this->assertNull($personal->customer);
    }

    public function test_a_customer_who_has_never_travelled_gets_nothing(): void
    {
        $customer = $this->signedInCustomer();

        $personal = Personalisation::for($customer->user);

        $this->assertFalse($personal->hasSomethingToSay());
        $this->assertNull($personal->greeting());
    }

    /** The refusal is a sentence somebody can read, not an absence. */
    public function test_the_reason_for_not_following_people_is_stated(): void
    {
        $why = Personalisation::for(null)->whyNothingPersonal();

        $this->assertStringContainsString('does not follow you around', $why);
        $this->assertStringContainsString('nothing is recommended from a profile', strtolower($why));
    }

    public function test_the_homepage_is_unchanged_for_a_visitor_who_is_not_signed_in(): void
    {
        $this->get(route('home', ['locale' => 'en']))
            ->assertSuccessful()
            ->assertDontSee('Welcome back');
    }

    // ── What a returning pilgrim sees ────────────────────────────────────

    public function test_a_returning_pilgrim_is_greeted_with_what_actually_happened(): void
    {
        $customer = $this->signedInCustomer('Placeholder Pilgrim');

        $flown = Departure::factory()->withSeats(30)->create([
            'package_id' => Package::factory()->create()->getKey(),
            'date_start' => now()->subMonths(8)->startOfDay(),
            'date_end' => now()->subMonths(8)->addDays(10)->startOfDay(),
        ]);

        $this->travelled($customer, $flown);

        $personal = Personalisation::for($customer->user);

        $this->assertTrue($personal->hasSomethingToSay());
        $this->assertSame(1, $personal->journeysTaken);
        $this->assertStringContainsString('Welcome back, Placeholder.', (string) $personal->greeting());
        $this->assertStringContainsString('travelled with us once', (string) $personal->greeting());
        $this->assertStringContainsString(now()->subMonths(8)->format('F Y'), (string) $personal->greeting());
    }

    public function test_a_cancelled_booking_is_not_a_journey_taken(): void
    {
        $customer = $this->signedInCustomer();

        $flown = Departure::factory()->withSeats(30)->create([
            'package_id' => Package::factory()->create()->getKey(),
            'date_start' => now()->subMonths(8)->startOfDay(),
            'date_end' => now()->subMonths(8)->addDays(10)->startOfDay(),
        ]);

        $this->travelled($customer, $flown, Booking::CANCELLED);

        $this->assertSame(0, Personalisation::for($customer->user)->journeysTaken);
    }

    /**
     * It recommends nothing, and the page says nothing it does not know.
     *
     * The first version offered "journeys on sale you have not been on",
     * and on the homepage that was the same list shown directly
     * underneath. What is personal here is knowing who the reader is, not
     * repeating the page back to them.
     */
    public function test_it_offers_no_recommendations_at_all(): void
    {
        $customer = $this->signedInCustomer();

        $flown = Departure::factory()->withSeats(30)->create([
            'package_id' => Package::factory()->create()->getKey(),
            'date_start' => now()->subMonths(8)->startOfDay(),
            'date_end' => now()->subMonths(8)->addDays(10)->startOfDay(),
        ]);
        $this->travelled($customer, $flown);

        $this->departure(40);

        $this->assertFalse(
            property_exists(Personalisation::for($customer->user), 'suggested'),
            'Personalisation has grown a recommendation list again.',
        );
    }

    // ── Somebody with a journey coming is not somebody to sell to ────────

    /**
     * Offering another package to somebody who is about to fly is how a
     * travel company reads as a shop rather than as the people taking them.
     */
    public function test_a_pilgrim_with_a_booking_is_pointed_at_their_portal_and_not_sold_to(): void
    {
        $customer = $this->signedInCustomer('Placeholder Pilgrim');
        $upcoming = $this->departure(40);

        $this->travelled($customer, $upcoming, Booking::CONFIRMED);
        $this->departure(90);

        $personal = Personalisation::for($customer->user);

        $this->assertTrue($personal->hasSomethingToSay());
        $this->assertFalse($personal->shouldSellAnything());
        $this->assertStringContainsString('Your journey is booked', (string) $personal->greeting());
    }

    public function test_the_homepage_greets_a_returning_pilgrim_and_nothing_more(): void
    {
        $customer = $this->signedInCustomer('Placeholder Pilgrim');

        $flown = Departure::factory()->withSeats(30)->create([
            'package_id' => Package::factory()->create(['title' => ['en' => 'Placeholder past package']])->getKey(),
            'date_start' => now()->subMonths(8)->startOfDay(),
            'date_end' => now()->subMonths(8)->addDays(10)->startOfDay(),
        ]);
        $this->travelled($customer, $flown);

        Departure::factory()->withSeats(30)->create([
            'package_id' => Package::factory()->create(['title' => ['en' => 'Placeholder next package']])->getKey(),
            'date_start' => now()->addDays(40)->startOfDay(),
            'date_end' => now()->addDays(50)->startOfDay(),
            'is_published' => true,
        ]);

        $this->actingAs($customer->user)
            ->get(route('home', ['locale' => 'en']))
            ->assertSuccessful()
            ->assertSee('Welcome back, Placeholder.')
            // And nothing else: the departures are already on this page.
            ->assertDontSee('journeys on sale that you have not been on', false);
    }

    public function test_the_homepage_does_not_sell_to_somebody_who_is_about_to_fly(): void
    {
        $customer = $this->signedInCustomer('Placeholder Pilgrim');

        $this->travelled($customer, $this->departure(40), Booking::CONFIRMED);
        $this->departure(90);

        $this->actingAs($customer->user)
            ->get(route('home', ['locale' => 'en']))
            ->assertSuccessful()
            ->assertSee('Your journey is booked')
            ->assertSee('is in your portal');
    }
}
