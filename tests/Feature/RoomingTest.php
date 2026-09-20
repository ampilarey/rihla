<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Traveller;
use App\Support\Rooming;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Rooming — §8.2, which calls this the place operator time disappears.
 *
 * Every test here is about a mistake being *found*. There is deliberately
 * no allocator: an algorithm that shuffles real pilgrims into rooms on
 * rules nobody has stated is how a mother is separated from her children on
 * a heuristic, and how a conflict nobody spotted becomes an argument at a
 * hotel desk at two in the morning.
 */
class RoomingTest extends TestCase
{
    use RefreshDatabase;

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(74),
        ]);
    }

    private function hotel(?Departure $departure = null): DepartureHotel
    {
        return DepartureHotel::factory()->create([
            'departure_id' => ($departure ?? $this->departure())->getKey(),
        ]);
    }

    /**
     * A confirmed booking with travellers of given ages and genders.
     *
     * @param  list<array{0: string, 1: string, 2: ?int}>  $people  name, gender, age at departure
     * @return Collection<int, Traveller>
     */
    private function confirmed(Departure $departure, array $people): Collection
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => count($people),
        ]);

        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        $travellers = collect();

        foreach ($people as [$name, $gender, $age]) {
            $traveller = Traveller::factory()->for($customer)->create([
                'full_name' => $name,
                'gender' => $gender,
                'date_of_birth' => $age === null
                    ? null
                    : $departure->date_start->copy()->subYears($age)->subMonths(1),
            ]);

            $booking->travellers()->create([
                'traveller_id' => $traveller->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $travellers->isEmpty(),
            ]);

            $travellers->push($traveller);
        }

        return $travellers;
    }

    private function place(Room $room, Traveller ...$travellers): void
    {
        foreach ($travellers as $traveller) {
            RoomAssignment::create([
                'room_id' => $room->getKey(),
                'traveller_id' => $traveller->getKey(),
            ]);
        }
    }

    /** @return list<string> the types of problem reported */
    private function types(DepartureHotel $hotel): array
    {
        return array_values(array_unique(array_column(Rooming::problemsWith($hotel->fresh()), 'type')));
    }

    // ── Nothing wrong ────────────────────────────────────────────────────

    public function test_a_correct_rooming_list_reports_nothing(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [
            ['Ibrahim Waheed', 'male', 45],
            ['Hassan Ali', 'male', 38],
        ]);

        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertSame([], Rooming::problemsWith($hotel->fresh()));
        $this->assertTrue(Rooming::isSettled($hotel->fresh()));
    }

    // ── Capacity ─────────────────────────────────────────────────────────

    /** The one that happens when a booking grows after the rooming was done. */
    public function test_more_people_than_beds_is_reported(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [
            ['Ibrahim Waheed', 'male', 45],
            ['Hassan Ali', 'male', 38],
            ['Ahmed Nasir', 'male', 50],
        ]);

        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertContains(Rooming::OVER_CAPACITY, $this->types($hotel));

        $problem = collect(Rooming::problemsWith($hotel->fresh()))
            ->firstWhere('type', Rooming::OVER_CAPACITY);

        $this->assertStringContainsString('3 people in a room with 2 beds', $problem['detail']);
    }

    public function test_an_empty_room_is_not_a_problem(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);

        Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);

        // Undesignated is reported for occupied rooms, not empty ones: an
        // empty room has nobody in it to be in the wrong one.
        $this->assertSame([], Rooming::problemsWith($hotel->fresh()));
    }

    // ── Gender ───────────────────────────────────────────────────────────

    public function test_men_and_women_sharing_a_non_family_room_is_reported(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [
            ['Ibrahim Waheed', 'male', 45],
            ['Aminath Zahira', 'female', 42],
        ]);

        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertContains(Rooming::MIXED_GENDER, $this->types($hotel));
    }

    /** A family room is mixed on purpose. */
    public function test_a_family_room_may_be_mixed(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [
            ['Ibrahim Waheed', 'male', 45],
            ['Aminath Zahira', 'female', 42],
            ['Aishath Waheed', 'female', 9],
        ]);

        $room = Room::factory()->beds(3)->family()->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertSame([], Rooming::problemsWith($hotel->fresh()));
    }

    public function test_a_womens_room_occupied_by_men_is_reported(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [['Ibrahim Waheed', 'male', 45]]);

        $room = Room::factory()->beds(2)->forWomen()->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $problem = collect(Rooming::problemsWith($hotel->fresh()))
            ->firstWhere('type', Rooming::MIXED_GENDER);

        $this->assertNotNull($problem);
        $this->assertStringContainsString('Marked for Women', $problem['detail']);
    }

    /** A blank on a rooming list sent to a hotel splits a family across floors. */
    public function test_a_room_nobody_has_designated_is_reported(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [['Ibrahim Waheed', 'male', 45]]);

        $room = Room::factory()->beds(2)->undesignated()->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertContains(Rooming::UNDESIGNATED, $this->types($hotel));
    }

    // ── Children ─────────────────────────────────────────────────────────

    public function test_a_child_alone_in_a_room_is_reported(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [
            ['Aishath Waheed', 'female', 9],
            ['Mariyam Waheed', 'female', 12],
        ]);

        $room = Room::factory()->beds(2)->forWomen()->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $problem = collect(Rooming::problemsWith($hotel->fresh()))
            ->firstWhere('type', Rooming::UNACCOMPANIED_CHILD);

        $this->assertNotNull($problem);
        $this->assertStringContainsString('Aishath Waheed', $problem['who']);
        $this->assertStringContainsString('Mariyam Waheed', $problem['who']);
    }

    public function test_a_child_with_an_adult_is_fine(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [
            ['Aminath Zahira', 'female', 42],
            ['Aishath Waheed', 'female', 9],
        ]);

        $room = Room::factory()->beds(2)->forWomen()->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertNotContains(Rooming::UNACCOMPANIED_CHILD, $this->types($hotel));
    }

    /**
     * Age at the departure date, not today.
     *
     * The first version of this used somebody who was fifteen on both
     * dates, so it passed whether the code measured from the departure or
     * from today — it proved nothing, and planting the defect is what
     * showed that. This one turns sixteen *between* now and the departure:
     * a child today, an adult when it matters, and flagged only by code
     * measuring from the wrong date.
     */
    public function test_age_is_measured_at_the_departure_and_not_today(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);

        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => 1,
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        // Sixteenth birthday thirty days from now; the departure is sixty.
        $traveller = Traveller::factory()->for($customer)->create([
            'full_name' => 'Sixteen By Departure',
            'gender' => 'male',
            'date_of_birth' => now()->copy()->subYears(16)->addDays(30),
        ]);

        $booking->travellers()->create([
            'traveller_id' => $traveller->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        $this->assertSame(15, $traveller->ageOn(now()), 'A child today.');
        $this->assertSame(16, $traveller->ageOn($departure->date_start), 'An adult at the departure.');

        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, $traveller);

        $this->assertNotContains(Rooming::UNACCOMPANIED_CHILD, $this->types($hotel));
    }

    /** And somebody still under the line at the departure is flagged. */
    public function test_a_child_at_the_departure_is_flagged(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [['Still Fifteen', 'male', 15]]);

        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertContains(Rooming::UNACCOMPANIED_CHILD, $this->types($hotel));
    }

    /** Guessing "child" would flag every traveller whose birthday nobody recorded. */
    public function test_an_unknown_date_of_birth_is_not_treated_as_a_child(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [['Nobody Recorded My Birthday', 'male', null]]);

        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertNotContains(Rooming::UNACCOMPANIED_CHILD, $this->types($hotel));
    }

    public function test_the_child_threshold_is_configuration(): void
    {
        config(['rooming.child_under' => 12]);

        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [['Fourteen', 'male', 14]]);

        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertNotContains(Rooming::UNACCOMPANIED_CHILD, $this->types($hotel));
    }

    public function test_the_child_check_can_be_switched_off(): void
    {
        config(['rooming.checks.unaccompanied_child' => false]);

        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [['Aishath Waheed', 'female', 9]]);

        $room = Room::factory()->beds(2)->forWomen()->create(['departure_hotel_id' => $hotel->getKey()]);
        $this->place($room, ...$people);

        $this->assertNotContains(Rooming::UNACCOMPANIED_CHILD, $this->types($hotel));
    }

    // ── Two rooms, and no room ───────────────────────────────────────────

    /** The commonest mistake after a re-plan. */
    public function test_somebody_in_two_rooms_is_reported(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [['Ibrahim Waheed', 'male', 45]]);

        $first = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey(), 'label' => '401']);
        $second = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey(), 'label' => '402']);

        $this->place($first, $people->first());
        $this->place($second, $people->first());

        $problem = collect(Rooming::problemsWith($hotel->fresh()))
            ->firstWhere('type', Rooming::DOUBLE_BOOKED);

        $this->assertNotNull($problem);
        $this->assertSame('401 and 402', $problem['room']);
    }

    /** The database refuses the same room twice. */
    public function test_the_same_room_twice_is_refused_outright(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $people = $this->confirmed($departure, [['Ibrahim Waheed', 'male', 45]]);
        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);

        $this->place($room, $people->first());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/UNIQUE|Duplicate entry/i');

        $this->place($room, $people->first());
    }

    /**
     * The problem that is invisible on a rooming list, because the person
     * is simply not on it.
     */
    public function test_a_confirmed_traveller_with_no_room_is_reported(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);
        $this->confirmed($departure, [['Forgotten Entirely', 'male', 45]]);

        $problem = collect(Rooming::problemsWith($hotel->fresh()))
            ->firstWhere('type', Rooming::UNROOMED);

        $this->assertNotNull($problem);
        $this->assertSame('Forgotten Entirely', $problem['who']);
    }

    /** A draft booking is not somebody who is going. */
    public function test_an_unconfirmed_traveller_is_not_owed_a_bed(): void
    {
        $departure = $this->departure();
        $hotel = $this->hotel($departure);

        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => 1,
        ]);
        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create()->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        $this->assertSame([], Rooming::problemsWith($hotel->fresh()));
    }

    // ── Two cities ───────────────────────────────────────────────────────

    /**
     * The reason rooms hang off the hotel stay rather than the departure: a
     * party is in a four-bed room in Makkah and a two-bed room in Madinah.
     */
    public function test_each_hotel_has_its_own_rooming(): void
    {
        $departure = $this->departure();
        $makkah = $this->hotel($departure);
        $madinah = DepartureHotel::factory()->inMadinah()->create(['departure_id' => $departure->getKey()]);

        $people = $this->confirmed($departure, [
            ['Ibrahim Waheed', 'male', 45],
            ['Hassan Ali', 'male', 38],
        ]);

        $big = Room::factory()->beds(4)->create(['departure_hotel_id' => $makkah->getKey()]);
        $small = Room::factory()->beds(2)->create(['departure_hotel_id' => $madinah->getKey()]);

        $this->place($big, ...$people);
        $this->place($small, ...$people);

        $this->assertSame([], Rooming::problemsWith($makkah->fresh()));
        $this->assertSame([], Rooming::problemsWith($madinah->fresh()));
    }

    public function test_a_room_in_one_hotel_does_not_satisfy_the_other(): void
    {
        $departure = $this->departure();
        $makkah = $this->hotel($departure);
        $madinah = DepartureHotel::factory()->inMadinah()->create(['departure_id' => $departure->getKey()]);

        $people = $this->confirmed($departure, [['Ibrahim Waheed', 'male', 45]]);

        $this->place(
            Room::factory()->beds(2)->create(['departure_hotel_id' => $makkah->getKey()]),
            $people->first(),
        );

        $this->assertSame([], Rooming::problemsWith($makkah->fresh()));
        $this->assertContains(Rooming::UNROOMED, $this->types($madinah));
    }
}
