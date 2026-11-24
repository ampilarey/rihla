<?php

namespace Modules\Transport\Tests;

use App\Exceptions\DeskRefusal;
use App\Models\Customer;
use App\Models\Island;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Models\Vessel;
use Modules\Transport\Services\TransportDesk;
use Tests\TestCase;

/**
 * Transport T1 (docs/TRANSPORT_PLAN.md): the timetable, holding seats under
 * one counter, fares, lapsing, cancelling, and payment confirming.
 */
class TransportDeskTest extends TestCase
{
    use RefreshDatabase;

    private BoatRoute $route;

    private Vessel $boat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 06:00'));

        $rihla = Partner::rihla();
        $this->assertNotNull($rihla);
        [$male, $fulidhoo] = Island::query()->orderBy('id')->limit(2)->get()->all();

        $this->boat = Vessel::create(['partner_id' => $rihla->id, 'name' => 'Rihla One', 'kind' => Vessel::SPEEDBOAT, 'seats' => 4]);
        $this->route = BoatRoute::create([
            'partner_id' => $rihla->id, 'origin_island_id' => $male->id, 'destination_island_id' => $fulidhoo->id,
            'duration_minutes' => 90, 'currency' => 'MVR', 'adult_fare_minor' => 50000, 'child_fare_minor' => 25000,
        ]);
    }

    private function desk(): TransportDesk
    {
        return app(TransportDesk::class);
    }

    private function sailing(int $seats = 4): Sailing
    {
        return Sailing::create(['boat_route_id' => $this->route->id, 'vessel_id' => $this->boat->id, 'departs_at' => now()->addDay()->setTime(7, 30), 'seats_total' => $seats]);
    }

    public function test_a_timetable_makes_one_sailing_per_chosen_day_and_time_and_never_twice(): void
    {
        $from = CarbonImmutable::parse('2026-11-30'); // a Monday
        $to = CarbonImmutable::parse('2026-12-06')->endOfDay();

        $made = $this->desk()->schedule($this->route, $this->boat, $from, $to, [1, 3, 5], ['07:30', '15:00']);
        $this->assertSame(6, $made, 'Monday, Wednesday and Friday, twice a day.');
        $this->assertSame(0, $this->desk()->schedule($this->route, $this->boat, $from, $to, [1, 3, 5], ['07:30', '15:00']));
        $this->assertSame(4, Sailing::query()->value('seats_total'), 'Every seat on the boat, when no number is given.');

        $this->expectException(DeskRefusal::class);
        $this->desk()->schedule($this->route, $this->boat, $from, $to, [1], ['7.30']);
    }

    public function test_a_sailing_never_sells_more_seats_than_its_boat_or_runs_on_another_operators_boat(): void
    {
        try {
            $this->sailing(5);
            $this->fail('A four-seat boat sold five.');
        } catch (DeskRefusal) {
            $this->assertSame(0, Sailing::count());
        }

        $other = Partner::factory()->create(['kind' => Partner::KIND_OPERATOR]);
        $theirs = Vessel::create(['partner_id' => $other->id, 'name' => 'Not ours', 'kind' => Vessel::LAUNCH, 'seats' => 40]);

        $this->expectException(DeskRefusal::class);
        Sailing::create(['boat_route_id' => $this->route->id, 'vessel_id' => $theirs->id, 'departs_at' => now()->addDay(), 'seats_total' => 10]);
    }

    public function test_booking_holds_seats_prices_children_and_refuses_what_is_not_there(): void
    {
        $sailing = $this->sailing();

        $booking = $this->desk()->book($sailing, [['full_name' => 'Aminath Ali'], ['full_name' => 'Ali Hassan', 'is_child' => true]], 'Aminath', '7771234');

        $this->assertSame(BoatBooking::HELD, $booking->status);
        $this->assertSame(75000, $booking->total_minor, 'An adult and a child.');
        $this->assertMatchesRegularExpression('/^RIH-T-2026-\d{5}$/', $booking->reference);
        $this->assertCount(2, $booking->tickets);
        $this->assertNotSame($booking->tickets[0]->token, $booking->tickets[1]->token);
        $this->assertSame(2, $sailing->fresh()->seats_taken);

        try {
            $this->desk()->book($sailing, [['full_name' => 'A'], ['full_name' => 'B'], ['full_name' => 'C']], 'X', '1');
            $this->fail('Three seats were sold where two were left.');
        } catch (DeskRefusal $refusal) {
            $this->assertSame('Only 2 seats are left on that sailing.', $refusal->getMessage());
        }

        $this->desk()->book($sailing, [['full_name' => 'C'], ['full_name' => 'D']], 'X', '1');
        $this->expectExceptionMessage('That sailing is full.');
        $this->desk()->book($sailing, [['full_name' => 'E']], 'X', '1');
    }

    public function test_an_abandoned_hold_gives_its_seats_back(): void
    {
        $sailing = $this->sailing();
        $this->desk()->book($sailing, array_fill(0, 4, ['full_name' => 'Somebody']), 'X', '1');

        $this->travel(16)->minutes();

        // The next booker reclaims it under the lock…
        $booking = $this->desk()->book($sailing, [['full_name' => 'Hawwa']], 'Hawwa', '2');
        $this->assertSame(BoatBooking::HELD, $booking->status);
        $this->assertSame(1, $sailing->fresh()->seats_taken);
        $this->assertSame(1, BoatBooking::query()->where('status', BoatBooking::LAPSED)->count());

        // …and the sweep reclaims the rest.
        $this->travel(16)->minutes();
        $this->artisan('transport:expire-holds')->expectsOutputToContain('Freed 1 seat(s).')->assertSuccessful();
        $this->assertSame(0, $sailing->fresh()->seats_taken);
    }

    public function test_cancelling_a_booking_or_a_sailing_gives_the_seats_back(): void
    {
        $sailing = $this->sailing();
        $one = $this->desk()->book($sailing, [['full_name' => 'A'], ['full_name' => 'B']], 'X', '1');
        $this->desk()->confirm($one);
        $two = $this->desk()->book($sailing, [['full_name' => 'C']], 'Y', '2');

        $this->desk()->cancel($two);
        $this->assertSame(2, $sailing->fresh()->seats_taken);

        $this->assertSame(1, $this->desk()->cancelSailing($sailing, 'Rough sea.'));
        $this->assertSame(BoatBooking::CANCELLED, $one->fresh()->status);
        $this->assertSame(Sailing::CANCELLED, $sailing->fresh()->status);
        $this->assertSame('Rough sea.', $sailing->fresh()->cancelled_reason);

        $this->expectException(DeskRefusal::class);
        $this->desk()->book($sailing->fresh(), [['full_name' => 'D']], 'Z', '3');
    }

    public function test_being_paid_in_full_confirms_a_held_booking(): void
    {
        $booking = $this->desk()->book($this->sailing(), [['full_name' => 'A']], 'X', '1');

        $booking->storePaidTotal(20000);
        $this->assertSame(BoatBooking::HELD, $booking->fresh()->status, 'A part payment does not confirm.');

        $booking->storePaidTotal(50000);
        $this->assertSame(BoatBooking::CONFIRMED, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->held_until, 'A confirmed booking no longer lapses.');
    }

    public function test_forgetting_a_customer_reaches_their_boat_bookings_and_passengers(): void
    {
        $customer = Customer::factory()->create();
        $booking = $this->desk()->book($this->sailing(), [['full_name' => 'Aminath Ali']], 'Aminath Ali', '7771234', $customer);
        $this->desk()->cancel($booking);

        $this->artisan('data:forget', ['customer' => (string) $customer->id])->assertSuccessful();

        $this->assertNotSame('Aminath Ali', DB::table('boat_bookings')->where('id', $booking->id)->value('contact_name'));
        $this->assertNotSame('7771234', DB::table('boat_bookings')->where('id', $booking->id)->value('contact_phone'));
        $this->assertNotSame('Aminath Ali', DB::table('boat_tickets')->where('boat_booking_id', $booking->id)->value('full_name'));
    }
}
