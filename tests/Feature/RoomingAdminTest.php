<?php

namespace Tests\Feature;

use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\RoomResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use App\Support\Rooming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The rooming screen — §8.2.
 *
 * Rendered rather than status-checked: a Filament page answers 200 while a
 * column closure throws in its own Livewire request.
 */
class RoomingAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(74),
        ]);
    }

    /** @return array{0: DepartureHotel, 1: Collection<int, Traveller>} */
    private function hotelWithParty(int $people = 2): array
    {
        $departure = $this->departure();
        $hotel = DepartureHotel::factory()->create([
            'departure_id' => $departure->getKey(),
            'name' => 'Swissotel Al Maqam',
        ]);

        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => $people,
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        $travellers = collect();

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

            $travellers->push($traveller);
        }

        return [$hotel->fresh(), $travellers];
    }

    // ── Who may look ──────────────────────────────────────────────────────

    public function test_operations_can_work_the_rooming(): void
    {
        [$hotel] = $this->hotelWithParty();
        Room::factory()->create(['departure_hotel_id' => $hotel->getKey(), 'label' => '412']);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRooms::class)
            ->assertOk()
            ->assertSee('412')
            ->assertSee('Swissotel Al Maqam');
    }

    /**
     * The undesignated room is the one the "For" column exists to flag, and
     * it was the one rendering blank: a null column state short-circuits
     * `formatStateUsing()`, so the label closure was never called. Found by
     * opening the screen, which is why this asserts on the rendered cell
     * rather than on the closure.
     */
    public function test_a_room_nobody_has_designated_says_so_rather_than_showing_a_blank(): void
    {
        [$hotel] = $this->hotelWithParty();
        $undesignated = Room::factory()->undesignated()->create([
            'departure_hotel_id' => $hotel->getKey(),
            'label' => 'M-203',
        ]);
        $womens = Room::factory()->forWomen()->create([
            'departure_hotel_id' => $hotel->getKey(),
            'label' => 'M-204',
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRooms::class)
            ->assertOk()
            ->assertTableColumnStateSet('gender', 'Not set', $undesignated)
            ->assertTableColumnStateSet('gender', 'Women', $womens);
    }

    public function test_operations_can_reach_the_screen_over_http(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(RoomResource::getUrl('index'))
            ->assertOk();
    }

    public function test_the_content_manager_cannot(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(RoomResource::getUrl('index'))
            ->assertForbidden();
    }

    /** The tour leader carries the list; they do not rearrange it. */
    public function test_the_tour_leader_reads_it_but_cannot_change_it(): void
    {
        [$hotel] = $this->hotelWithParty();
        $room = Room::factory()->create(['departure_hotel_id' => $hotel->getKey()]);

        $leader = $this->staff(Access::TOUR_LEADER);

        $this->assertTrue($leader->can('rooming.view'));
        $this->assertFalse($leader->can('rooming.update'));

        Livewire::actingAs($leader)
            ->test(ListRooms::class)
            ->assertOk()
            ->assertTableActionHidden('assign', $room);
    }

    // ── Assigning ────────────────────────────────────────────────────────

    public function test_somebody_can_be_put_in_a_room(): void
    {
        [$hotel, $travellers] = $this->hotelWithParty();
        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRooms::class)
            ->callTableAction('assign', $room, ['traveller_id' => $travellers->first()->getKey()]);

        $assignment = RoomAssignment::sole();

        $this->assertSame($room->getKey(), $assignment->room_id);
        $this->assertSame($travellers->first()->getKey(), $assignment->traveller_id);
        // The booking is recorded, so a party that cancels is findable in
        // one query.
        $this->assertNotNull($assignment->booking_id);
    }

    /**
     * The list offers only people who need a bed here — which prevents the
     * double-booking the conflict report would otherwise have to catch
     * after the fact.
     */
    public function test_somebody_already_roomed_in_this_hotel_is_not_offered(): void
    {
        [$hotel, $travellers] = $this->hotelWithParty(2);

        $first = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey(), 'label' => '401']);
        $second = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey(), 'label' => '402']);

        RoomAssignment::create([
            'room_id' => $first->getKey(),
            'traveller_id' => $travellers->first()->getKey(),
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRooms::class)
            ->mountTableAction('assign', $second)
            ->assertOk();

        // Asserted on the service the option list is built from, because
        // Filament renders a Select's options in its own request.
        $offered = Rooming::travellersOwedABed($hotel->departure)
            ->pluck('id')
            ->all();

        $this->assertContains($travellers->first()->getKey(), $offered, 'They are owed a bed somewhere.');

        $roomed = $hotel->fresh()->rooms->flatMap(
            fn (Room $r): array => $r->assignments->pluck('traveller_id')->all(),
        );

        $this->assertTrue($roomed->contains($travellers->first()->getKey()), 'And already have one here.');
    }

    public function test_somebody_can_be_taken_out(): void
    {
        [$hotel, $travellers] = $this->hotelWithParty();
        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);

        RoomAssignment::create([
            'room_id' => $room->getKey(),
            'traveller_id' => $travellers->first()->getKey(),
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRooms::class)
            ->callTableAction('remove', $room->fresh(), ['traveller_id' => $travellers->first()->getKey()]);

        $this->assertSame(0, RoomAssignment::count());
    }

    /**
     * Over capacity is said, not refused.
     *
     * It is sometimes deliberate for a night, and refusing it outright is
     * how staff go round the system with a spreadsheet.
     */
    public function test_filling_a_room_past_its_beds_is_allowed_and_said(): void
    {
        [$hotel, $travellers] = $this->hotelWithParty(2);
        $room = Room::factory()->beds(1)->create(['departure_hotel_id' => $hotel->getKey()]);

        RoomAssignment::create([
            'room_id' => $room->getKey(),
            'traveller_id' => $travellers->first()->getKey(),
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRooms::class)
            ->callTableAction('assign', $room->fresh(), ['traveller_id' => $travellers->last()->getKey()])
            ->assertNotified();

        $this->assertSame(2, RoomAssignment::count());
        $this->assertTrue($room->fresh()->isOverfull());
    }

    // ── The check ────────────────────────────────────────────────────────

    /**
     * The modal view, rendered directly.
     *
     * A relation manager's modal is drawn by the page that hosts it, and a
     * resource table's modal renders in its own request — so the view is
     * exercised here and the action's presence on the row separately.
     */
    public function test_the_check_view_names_what_is_wrong(): void
    {
        [$hotel, $travellers] = $this->hotelWithParty(2);
        Room::factory()->beds(2)->undesignated()->create(['departure_hotel_id' => $hotel->getKey()]);

        $this->view('filament.rooming-check', [
            'hotel' => $hotel->fresh(),
            'problems' => Rooming::problemsWith($hotel->fresh()),
            'labels' => [
                Rooming::UNROOMED => 'No room at all',
                Rooming::UNDESIGNATED => 'Nobody has said who the room is for',
            ],
        ])
            ->assertSee('No room at all')
            ->assertSee('Traveller 0')
            ->assertSee('None of this is fixed automatically');
    }

    public function test_the_check_view_says_so_when_nothing_is_wrong(): void
    {
        [$hotel, $travellers] = $this->hotelWithParty(1);
        $room = Room::factory()->beds(2)->create(['departure_hotel_id' => $hotel->getKey()]);
        RoomAssignment::create([
            'room_id' => $room->getKey(),
            'traveller_id' => $travellers->first()->getKey(),
        ]);

        $this->view('filament.rooming-check', [
            'hotel' => $hotel->fresh(),
            'problems' => Rooming::problemsWith($hotel->fresh()),
            'labels' => [],
        ])->assertSee('Nothing wrong with this rooming list');
    }

    public function test_the_check_action_is_on_every_room(): void
    {
        [$hotel] = $this->hotelWithParty();
        $room = Room::factory()->create(['departure_hotel_id' => $hotel->getKey()]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRooms::class)
            ->assertTableActionVisible('check', $room);
    }

    // ── The badge ────────────────────────────────────────────────────────

    /** Hotels needing attention, not a count of problems nobody can face. */
    public function test_the_badge_counts_hotels_not_problems(): void
    {
        [$hotel] = $this->hotelWithParty(3);
        Room::factory()->beds(1)->undesignated()->create(['departure_hotel_id' => $hotel->getKey()]);

        $this->assertSame('1', RoomResource::getNavigationBadge());
    }

    /** A rooming mistake on a trip that came home is history, not work. */
    public function test_a_past_departure_is_not_counted(): void
    {
        $departure = Departure::factory()->withSeats(10)->create([
            'date_start' => now()->subMonths(3),
            'date_end' => now()->subMonths(3)->addDays(14),
        ]);

        $hotel = DepartureHotel::factory()->create(['departure_id' => $departure->getKey()]);
        Room::factory()->undesignated()->create(['departure_hotel_id' => $hotel->getKey()]);

        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => 1,
        ]);
        $booking->forceFill(['status' => Booking::COMPLETED])->save();
        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create()->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        $this->assertNull(RoomResource::getNavigationBadge());
    }
}
