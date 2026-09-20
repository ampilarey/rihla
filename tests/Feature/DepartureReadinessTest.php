<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\Person;
use App\Models\Room;
use App\Models\Traveller;
use App\Models\WaitlistEntry;
use App\Support\DepartureReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Is this departure ready to fly — §8.2.
 *
 * The distinction these tests exist to hold is between **blocking** (a
 * traveller cannot go, or the departure cannot run as sold) and
 * **attention** (it goes, and somebody has a bad time). Collapsing the two
 * is how a real blocker ends up buried under six warnings.
 */
class DepartureReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays(40),
            'date_end' => now()->addDays(54),
            'tour_leader_id' => Person::factory()->create()->getKey(),
            'scholar_id' => Person::factory()->create()->getKey(),
        ]);
    }

    /** A confirmed booking, paid in full, with travellers who cannot travel. */
    private function confirmedBooking(Departure $departure, int $people = 1, int $totalMinor = 0): Booking
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => $people,
        ]);

        $booking->forceFill([
            'status' => Booking::CONFIRMED,
            'total_minor' => $totalMinor,
            'paid_minor' => $totalMinor,
        ])->save();

        for ($i = 0; $i < $people; $i++) {
            $traveller = Traveller::factory()->for($customer)->create([
                'full_name' => "Traveller {$i}",
                'gender' => 'male',
                'date_of_birth' => now()->subYears(40),
            ]);

            $booking->travellers()->create([
                'traveller_id' => $traveller->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $i === 0,
            ]);
        }

        return $booking->fresh();
    }

    /** One party waiting. `WaitlistEntry` has no factory; this is the whole of one. */
    private function queueOnePartyFor(Departure $departure): WaitlistEntry
    {
        $entry = WaitlistEntry::create([
            'departure_id' => $departure->getKey(),
            'customer_id' => Customer::factory()->create()->getKey(),
            'seats' => 1,
        ]);

        $entry->forceFill(['status' => WaitlistEntry::WAITING])->save();

        return $entry;
    }

    /** @return list<array{area: string, severity: string, headline: string, detail: string}> */
    private function concernsIn(Departure $departure, string $area): array
    {
        return array_values(array_filter(
            DepartureReadiness::concerns($departure),
            fn (array $concern): bool => $concern['area'] === $area,
        ));
    }

    // ── Nothing to say ───────────────────────────────────────────────────

    public function test_a_departure_with_nobody_on_it_has_nothing_outstanding(): void
    {
        $departure = $this->departure();

        $this->assertSame([], DepartureReadiness::concerns($departure));
        $this->assertFalse(DepartureReadiness::hasBlockers($departure));
    }

    // ── Travel documents [R-4] ───────────────────────────────────────────

    public function test_a_traveller_without_documents_blocks_the_departure(): void
    {
        $departure = $this->departure();
        $this->confirmedBooking($departure);

        $concerns = $this->concernsIn($departure, DepartureReadiness::TRAVEL_DOCUMENTS);

        $this->assertCount(1, $concerns);
        $this->assertSame(DepartureReadiness::BLOCKING, $concerns[0]['severity']);
        $this->assertSame('One traveller cannot go yet', $concerns[0]['headline']);
        $this->assertTrue(DepartureReadiness::hasBlockers($departure));
    }

    /**
     * Counted by requirement, not by person.
     *
     * "Two travellers are not ready" is a number. "2 passports, 2 visas and
     * 2 Umrah permits" is the work. [R-4] keeps the three apart because
     * they are granted by different bodies and fail differently.
     */
    public function test_the_outstanding_requirements_are_named_and_counted(): void
    {
        $departure = $this->departure();
        $this->confirmedBooking($departure, people: 2);

        $concerns = $this->concernsIn($departure, DepartureReadiness::TRAVEL_DOCUMENTS);

        $this->assertSame('2 travellers cannot go yet', $concerns[0]['headline']);
        $this->assertStringContainsString('2 passports', $concerns[0]['detail']);
        $this->assertStringContainsString('2 visas', $concerns[0]['detail']);
        $this->assertStringContainsString('2 Umrah permits', $concerns[0]['detail']);
    }

    public function test_a_draft_booking_does_not_make_a_departure_un_ready(): void
    {
        $departure = $this->departure();

        $booking = Booking::factory()->create([
            'departure_id' => $departure->getKey(),
            'seats' => 1,
        ]);

        $traveller = Traveller::factory()->create();
        $booking->travellers()->create([
            'traveller_id' => $traveller->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        $this->assertSame([], $this->concernsIn($departure, DepartureReadiness::TRAVEL_DOCUMENTS));
    }

    // ── Money [R-7] ──────────────────────────────────────────────────────

    public function test_an_outstanding_balance_blocks_the_departure(): void
    {
        $departure = $this->departure();
        $booking = $this->confirmedBooking($departure);
        $booking->forceFill(['total_minor' => 2_850_000, 'paid_minor' => 1_000_000])->save();

        $concerns = $this->concernsIn($departure, DepartureReadiness::MONEY);

        $this->assertCount(1, $concerns);
        $this->assertSame(DepartureReadiness::BLOCKING, $concerns[0]['severity']);
        $this->assertSame('MVR 18,500 still owed', $concerns[0]['headline']);
    }

    public function test_a_booking_paid_in_full_says_nothing_about_money(): void
    {
        $departure = $this->departure();
        $this->confirmedBooking($departure, totalMinor: 2_850_000);

        $this->assertSame([], $this->concernsIn($departure, DepartureReadiness::MONEY));
    }

    /**
     * Two currencies are never added together.
     *
     * Every booking today is in rufiyaa. Adding laari to cents the day one
     * is not would produce a number nobody checks again, so they are
     * reported separately.
     */
    public function test_two_currencies_are_reported_separately(): void
    {
        $departure = $this->departure();

        $this->confirmedBooking($departure)
            ->forceFill(['currency' => 'MVR', 'total_minor' => 100_000, 'paid_minor' => 0])->save();

        $this->confirmedBooking($departure)
            ->forceFill(['currency' => 'USD', 'total_minor' => 50_000, 'paid_minor' => 0])->save();

        $headlines = array_column($this->concernsIn($departure, DepartureReadiness::MONEY), 'headline');

        $this->assertContains('MVR 1,000 still owed', $headlines);
        $this->assertContains('USD 500 still owed', $headlines);
    }

    // ── Rooming ──────────────────────────────────────────────────────────

    /** It goes ahead; the argument happens at the hotel desk. */
    public function test_an_unsettled_rooming_list_is_attention_not_a_blocker(): void
    {
        $departure = $this->departure();
        $this->confirmedBooking($departure, totalMinor: 0);

        DepartureHotel::factory()->create([
            'departure_id' => $departure->getKey(),
            'name' => 'Swissotel Al Maqam',
        ]);

        $concerns = $this->concernsIn($departure->fresh(), DepartureReadiness::ROOMING);

        $this->assertCount(1, $concerns);
        $this->assertSame(DepartureReadiness::ATTENTION, $concerns[0]['severity']);
        $this->assertStringContainsString('Swissotel Al Maqam', $concerns[0]['headline']);
    }

    public function test_a_settled_rooming_list_says_nothing(): void
    {
        $departure = $this->departure();
        $booking = $this->confirmedBooking($departure, totalMinor: 0);

        $hotel = DepartureHotel::factory()->create(['departure_id' => $departure->getKey()]);
        $room = Room::factory()->create([
            'departure_hotel_id' => $hotel->getKey(),
            'capacity' => 4,
        ]);

        $room->assignments()->create([
            'traveller_id' => $booking->travellers->first()->traveller_id,
            'booking_id' => $booking->getKey(),
        ]);

        $this->assertSame([], $this->concernsIn($departure->fresh(), DepartureReadiness::ROOMING));
    }

    // ── Staffing ─────────────────────────────────────────────────────────

    public function test_no_tour_leader_blocks_and_no_scholar_does_not(): void
    {
        $departure = $this->departure();
        $departure->forceFill(['tour_leader_id' => null, 'scholar_id' => null])->save();

        $concerns = $this->concernsIn($departure->fresh(), DepartureReadiness::STAFFING);

        $bySeverity = [];
        foreach ($concerns as $concern) {
            $bySeverity[$concern['headline']] = $concern['severity'];
        }

        $this->assertSame(DepartureReadiness::BLOCKING, $bySeverity['No tour leader']);
        $this->assertSame(DepartureReadiness::ATTENTION, $bySeverity['No scholar']);
    }

    public function test_a_fully_staffed_departure_says_nothing_about_staffing(): void
    {
        $this->assertSame([], $this->concernsIn($this->departure(), DepartureReadiness::STAFFING));
    }

    // ── Capacity and the queue ───────────────────────────────────────────

    public function test_seats_held_but_never_confirmed_are_worth_looking_at(): void
    {
        $departure = $this->departure();
        $departure->forceFill(['capacity_held' => 3])->save();

        $concerns = $this->concernsIn($departure->fresh(), DepartureReadiness::CAPACITY);

        $this->assertCount(1, $concerns);
        $this->assertSame(DepartureReadiness::ATTENTION, $concerns[0]['severity']);
        $this->assertSame('3 seats held, not confirmed', $concerns[0]['headline']);
    }

    public function test_somebody_waiting_while_seats_are_free_is_reported(): void
    {
        $departure = $this->departure();

        $this->queueOnePartyFor($departure);

        $concerns = $this->concernsIn($departure->fresh(), DepartureReadiness::WAITLIST);

        $this->assertCount(1, $concerns);
        $this->assertSame(DepartureReadiness::ATTENTION, $concerns[0]['severity']);
    }

    /**
     * A queue on a full departure is normal, and saying so on every sold-out
     * trip is how a board becomes noise somebody stops reading.
     */
    public function test_a_queue_on_a_full_departure_is_not_reported(): void
    {
        $departure = Departure::factory()->soldOut()->create([
            'date_start' => now()->addDays(40),
            'tour_leader_id' => Person::factory()->create()->getKey(),
            'scholar_id' => Person::factory()->create()->getKey(),
        ]);

        $this->queueOnePartyFor($departure);

        $this->assertSame([], $this->concernsIn($departure->fresh(), DepartureReadiness::WAITLIST));
    }

    // ── Blocking versus attention ────────────────────────────────────────

    /**
     * The distinction this whole class exists for: a departure with three
     * things worth looking at and nothing blocking still flies.
     */
    public function test_attention_alone_does_not_block_a_departure(): void
    {
        $departure = $this->departure();
        $departure->forceFill(['scholar_id' => null, 'capacity_held' => 2])->save();

        $departure = $departure->fresh();

        $this->assertNotSame([], DepartureReadiness::concerns($departure));
        $this->assertFalse(DepartureReadiness::hasBlockers($departure));
    }
}
