<?php

namespace Tests\Feature;

use App\Filament\Pages\Scan;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\BusGroups\Pages\ListBusGroups;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\BusGroup;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Operations\Buses;
use App\Services\Operations\Cards;
use App\Services\Operations\Families;
use App\Services\Operations\Lists;
use App\Support\Access;
use App\Support\Anonymisation;
use App\Support\Rooming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Families — batch C from Hijaz, as the owner chose it: the travellers one
 * person booked onto one departure, shown as one group on the booking,
 * roomed and bussed together by default, with a head who holds the
 * family's documents and is the one we call.
 *
 * The family that matters in these tests arrives in two pieces, the way it
 * does at the desk: a son books his parents, then adds his sister on a
 * second booking a week later. Grouped by booking, she would be a family
 * of one.
 */
class FamiliesTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(40)->create([
            'date_start' => now()->addDays(30),
            'date_end' => now()->addDays(44),
        ]);
    }

    /**
     * A booking by $booker of these people, the first its lead.
     *
     * @param  list<array{0: string, 1?: string, 2?: int, 3?: ?string}>  $people  name, gender, age at departure, relationship to the booker
     */
    private function book(Customer $booker, Departure $departure, array $people, string $status = Booking::CONFIRMED): Booking
    {
        $booking = Booking::factory()->create([
            'customer_id' => $booker->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => count($people),
        ]);
        $booking->forceFill(['status' => $status])->save();

        foreach ($people as $i => $person) {
            $traveller = Traveller::factory()->for($booker)->create([
                'full_name' => $person[0],
                'gender' => $person[1] ?? Traveller::FEMALE,
                'date_of_birth' => $departure->date_start->copy()->subYears($person[2] ?? 40)->subMonth(),
                // The factory says "self" for everybody; a family is not.
                'relationship' => $person[3] ?? null,
            ]);

            $booking->travellers()->create([
                'traveller_id' => $traveller->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $i === 0,
            ]);
        }

        return $booking->fresh(['travellers.traveller', 'customer']);
    }

    /**
     * Hassan books his parents, then his sister on a second booking; the
     * Ibrahims are somebody else's family on the same departure.
     *
     * @return array{departure: Departure, hassan: Customer, parents: Booking, sister: Booking, ibrahim: Booking}
     */
    private function hassansFamily(): array
    {
        $departure = $this->departure();
        $hassan = Customer::factory()->create(['name' => 'Hassan Ahmed', 'phone' => '7771234']);

        $parents = $this->book($hassan, $departure, [
            ['Ahmed Ali', Traveller::MALE, 62, 'parent'],
            ['Aminath Ali', Traveller::FEMALE, 58, 'parent'],
        ]);
        $sister = $this->book($hassan, $departure, [['Mariyam Ahmed', Traveller::FEMALE, 30, 'sibling']]);

        $ibrahim = $this->book(Customer::factory()->create(['name' => 'Ibrahim Waheed', 'phone' => '7779999']), $departure, [
            ['Ibrahim Waheed', Traveller::MALE, 45, 'self'],
        ]);

        // Neither of these is travelling with them.
        $this->book($hassan, $departure, [['Cancelled Cousin', Traveller::MALE, 25]], Booking::CANCELLED);
        $this->book($hassan, $this->departure(), [['Next Year', Traveller::MALE, 25]]);

        return compact('departure', 'hassan', 'parents', 'sister', 'ibrahim');
    }

    private function line(Booking $booking, string $name): BookingTraveller
    {
        return $booking->travellers->first(fn (BookingTraveller $line): bool => $line->traveller->full_name === $name)
            ?? $this->fail('No '.$name.' on '.$booking->reference);
    }

    public function test_a_family_is_everybody_one_person_booked_onto_the_departure(): void
    {
        ['departure' => $departure, 'parents' => $parents, 'sister' => $sister] = $this->hassansFamily();
        $families = app(Families::class);

        $onDeparture = $families->onDeparture($departure);
        $this->assertCount(2, $onDeparture, 'Hassan\'s family and the Ibrahims; not the cancelled cousin, not next year.');

        $family = $families->forBooking($sister);
        $this->assertNotNull($family);
        $this->assertSame("Hassan Ahmed's family", $family->name());
        $this->assertSame(['Ahmed Ali', 'Aminath Ali', 'Mariyam Ahmed'], $family->members->map(fn (BookingTraveller $m): string => $m->traveller->full_name)->all());
        $this->assertSame('Ahmed Ali', $family->head->traveller->full_name, 'The lead on the earliest booking heads the family.');
        $this->assertSame([$parents->getKey(), $sister->getKey()], $family->bookingIds());

        $this->assertSame("Hassan Ahmed's father", $family->relationOf($this->line($parents, 'Ahmed Ali')));
        $this->assertSame("Hassan Ahmed's mother", $family->relationOf($this->line($parents, 'Aminath Ali')));
        $this->assertSame("Hassan Ahmed's sister", $family->relationOf($this->line($sister, 'Mariyam Ahmed')));

        // The head has no phone of their own yet: the booker answers, and says so.
        $mariyam = $this->line($sister, 'Mariyam Ahmed');
        $this->assertSame('7771234', $family->phoneFor($mariyam));
        $this->assertSame('booker', $family->phoneSourceFor($mariyam));
        $this->assertSame('Hassan Ahmed, who booked, on 7771234 · no phone on file for Ahmed Ali, the head', $family->contactWords());

        // Given one, the head is the one we call — for all of them.
        $this->line($parents, 'Ahmed Ali')->traveller->update(['phone' => '+966 50 123 4567']);
        $family = $families->forBooking($sister);
        $this->assertSame('+966 50 123 4567', $family?->phoneFor($mariyam));
        $this->assertSame('head', $family?->phoneSourceFor($mariyam));
        $this->assertSame('Ahmed Ali, the head, on +966 50 123 4567', $family?->contactWords());

        // And somebody with a phone of their own is rung on it.
        $mariyam->traveller->update(['phone' => '7775555']);
        $this->assertSame('7775555', $families->forBooking($sister)?->phoneFor($mariyam->fresh()));

        // One head for the family, across both bookings.
        $families->makeHead($mariyam->fresh());
        $family = $families->forBooking($parents);
        $this->assertSame('Mariyam Ahmed', $family?->head->traveller->full_name);
        $this->assertSame(1, BookingTraveller::query()->whereIn('booking_id', [$parents->getKey(), $sister->getKey()])->where('is_lead', true)->count());
        $this->assertSame('Mariyam Ahmed', $family?->members->first()?->traveller->full_name, 'The head comes first.');
    }

    public function test_the_booking_shows_the_family_and_the_family_button_sets_it(): void
    {
        ['parents' => $parents, 'sister' => $sister] = $this->hassansFamily();
        $ahmed = $this->line($parents, 'Ahmed Ali');
        $aminath = $this->line($parents, 'Aminath Ali');

        $this->actingAs($this->staff(Access::BOOKING_STAFF))
            ->get(BookingResource::getUrl('edit', ['record' => $parents]))
            ->assertOk()
            ->assertSee('Everyone the same person booked onto this departure travels as one family')
            ->assertSee("Ahmed Ali (head of the family · Hassan Ahmed's father)")
            ->assertSee("Mariyam Ahmed (Hassan Ahmed's sister) — on booking ".$sister->reference)
            ->assertSee('no phone on file for Ahmed Ali, the head');

        // One form for the party: the head, how each is related, and a phone.
        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $parents->getKey()])
            ->assertActionVisible('family')
            ->callAction('family', data: [
                'head' => $aminath->getKey(),
                'people' => [
                    $ahmed->getKey() => ['relationship' => 'parent', 'phone' => ''],
                    $aminath->getKey() => ['relationship' => 'parent', 'phone' => '+966 55 765 4321'],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Family saved');

        // Written through the guarded path: a column missing from $fillable
        // would be dropped here without a word.
        $this->assertSame('+966 55 765 4321', $aminath->traveller->fresh()->phone);
        $this->assertNull($ahmed->traveller->fresh()->phone, 'An empty box is no phone, not an empty string.');
        $this->assertTrue($aminath->fresh()->is_lead);
        $this->assertFalse($ahmed->fresh()->is_lead);
        $this->assertFalse($this->line($sister, 'Mariyam Ahmed')->fresh()->is_lead, 'One head, not one per booking.');

        $this->actingAs($this->staff(Access::FINANCE))
            ->get(BookingResource::getUrl('view', ['record' => $sister]))
            ->assertOk()
            ->assertSee('Aminath Ali, the head, on +966 55 765 4321');

        // Reading a booking is not changing its family.
        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(ViewBooking::class, ['record' => $sister->getKey()])
            ->assertActionDoesNotExist('family');

        // The desk's new-traveller form writes both through the same path.
        $created = Traveller::query()->create([
            'customer_id' => $parents->customer_id,
            'full_name' => 'Hawwa Ahmed',
            'relationship' => 'child',
            'phone' => '7770000',
        ]);
        $this->assertSame('child', $created->fresh()->relationship);
        $this->assertSame('7770000', $created->fresh()->phone);
    }

    public function test_a_family_goes_on_a_bus_together_unless_somebody_splits_it(): void
    {
        ['departure' => $departure, 'parents' => $parents, 'sister' => $sister, 'ibrahim' => $ibrahim] = $this->hassansFamily();
        $bus1 = BusGroup::create(['departure_id' => $departure->getKey(), 'name' => 'Bus 1', 'leader_name' => 'Ahmed']);
        $bus2 = BusGroup::create(['departure_id' => $departure->getKey(), 'name' => 'Bus 2', 'leader_name' => 'Ibrahim']);
        $aminath = $this->line($parents, 'Aminath Ali');
        $mariyam = $this->line($sister, 'Mariyam Ahmed');

        $page = Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))->test(ListBusGroups::class);

        // Choosing the mother brings her husband and, from the other booking, her daughter.
        $page->callTableAction('assign', $bus1, data: ['ids' => [$aminath->getKey()]])
            ->assertHasNoTableActionErrors()
            ->assertNotified('3 put on Bus 1');

        $this->assertSame(
            [$bus1->getKey(), $bus1->getKey(), $bus1->getKey()],
            [$this->line($parents, 'Ahmed Ali')->fresh()->bus_group_id, $aminath->fresh()->bus_group_id, $mariyam->fresh()->bus_group_id],
        );
        $this->assertNull($this->line($ibrahim, 'Ibrahim Waheed')->fresh()->bus_group_id, 'Somebody else\'s family stays where it was.');

        // Split on purpose: allowed, and said, across the two bookings.
        $page->callTableAction('assign', $bus2, data: ['ids' => [$mariyam->getKey()], 'with_family' => false])
            ->assertHasNoTableActionErrors()
            ->assertNotified('1 put on Bus 2');

        $split = collect(app(Buses::class)->problemsWith($departure))->firstWhere('type', Buses::SPLIT_FAMILY);
        $this->assertNotNull($split, 'A family on two bookings is still one family.');
        $this->assertSame("Hassan Ahmed's family is split across Bus 1 and Bus 2.", $split['detail']);

        // Bringing the family again gathers them from the other bus.
        $page->callTableAction('assign', $bus2, data: ['ids' => [$aminath->getKey()]])
            ->assertNotified('2 put on Bus 2');
        $this->assertNull(collect(app(Buses::class)->problemsWith($departure))->firstWhere('type', Buses::SPLIT_FAMILY));
    }

    public function test_a_family_is_roomed_together_and_a_child_needs_an_adult_of_their_own(): void
    {
        $departure = $this->departure();
        $hotel = DepartureHotel::factory()->create(['departure_id' => $departure->getKey(), 'name' => 'Swissotel Al Maqam', 'city' => 'makkah']);
        $familyRoom = Room::factory()->beds(4)->create(['departure_hotel_id' => $hotel->getKey(), 'label' => '101', 'gender' => Room::FAMILY]);
        $womens = Room::factory()->beds(2)->forWomen()->create(['departure_hotel_id' => $hotel->getKey(), 'label' => '102']);

        $zahira = $this->book(Customer::factory()->create(['name' => 'Zahira Ali']), $departure, [
            ['Zahira Ali', Traveller::FEMALE, 41, 'self'],
            ['Shifa Ali', Traveller::FEMALE, 9, 'child'],
            ['Ismail Ali', Traveller::MALE, 12, 'child'],
        ]);
        $fathimath = $this->book(Customer::factory()->create(['name' => 'Fathimath Saeed']), $departure, [['Fathimath Saeed', Traveller::FEMALE, 50, 'self']]);
        $hawwa = $this->book(Customer::factory()->create(['name' => 'Moosa Rasheed']), $departure, [
            ['Moosa Rasheed', Traveller::MALE, 44, 'self'],
            ['Hawwa Moosa', Traveller::FEMALE, 8, 'child'],
        ]);

        $page = Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))->test(ListRooms::class);

        // Choosing the daughter brings her mother and her brother.
        $page->callTableAction('assign', $familyRoom, ['traveller_id' => $this->line($zahira, 'Shifa Ali')->traveller_id])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Shifa Ali and 2 of their family are in room 101');

        $this->assertSame(3, RoomAssignment::query()->where('room_id', $familyRoom->getKey())->count());

        // A woman of another family is not supervision for a child.
        $page->callTableAction('assign', $womens, ['traveller_id' => $this->line($fathimath, 'Fathimath Saeed')->traveller_id]);
        $page->callTableAction('assign', $womens->fresh(), ['traveller_id' => $this->line($hawwa, 'Hawwa Moosa')->traveller_id, 'with_family' => false])
            ->assertNotified('Hawwa Moosa is in room 102');

        $alone = collect(Rooming::problemsWith($hotel->fresh()))->firstWhere('type', Rooming::UNACCOMPANIED_CHILD);
        $this->assertNotNull($alone, 'Fathimath is an adult, and not Hawwa\'s family.');
        $this->assertSame('102', $alone['room']);
        $this->assertSame('Hawwa Moosa', $alone['who']);
        $this->assertSame('Under 16, with no adult of their family in the room.', $alone['detail']);

        // Her father joins her, and the room is fine.
        RoomAssignment::create(['room_id' => $womens->getKey(), 'traveller_id' => $this->line($hawwa, 'Moosa Rasheed')->traveller_id]);
        $this->assertNull(collect(Rooming::problemsWith($hotel->fresh()))->firstWhere('type', Rooming::UNACCOMPANIED_CHILD));
    }

    public function test_the_lists_and_the_scan_page_ring_the_family_head(): void
    {
        ['departure' => $departure, 'parents' => $parents, 'sister' => $sister] = $this->hassansFamily();
        $families = app(Families::class);
        $mariyam = $this->line($sister, 'Mariyam Ahmed');

        // Mariyam heads the family and carries its phone; she sorts after her parents by name.
        $families->makeHead($mariyam);
        $mariyam->traveller->update(['phone' => '+966 50 111 2222']);

        $office = app(Lists::class)->manifest($departure, 'office');
        $names = array_column($office->rows, 'name');
        $this->assertSame(['Mariyam Ahmed (head)', 'Ahmed Ali', 'Aminath Ali', 'Ibrahim Waheed (head)'], $names, 'Each family together, its head first.');
        $this->assertSame(['+966 50 111 2222', '+966 50 111 2222', '+966 50 111 2222', '7779999'], array_column($office->rows, 'phone'), 'The family is rung on the head\'s number; a family with none, on the booker\'s.');
        $this->assertStringContainsString('Phone is the traveller\'s own, or else their family head\'s, or else the person who booked.', implode(' ', $office->notes));

        $documents = app(Lists::class)->documents($departure);
        $this->assertSame('Mariyam Ahmed (head)', $documents->rows[0]['name']);

        // A lost father's tag, scanned: the number is the head's.
        $card = app(Cards::class)->issue($this->line($parents, 'Ahmed Ali'), Card::LUGGAGE);

        Livewire::actingAs($this->staff(Access::TOUR_LEADER))
            ->test(Scan::class)
            ->set('code', $card->serial)
            ->call('lookup')
            ->assertSee('Family head')
            ->assertSee('Mariyam Ahmed')
            ->assertSee('Phone (family head)')
            ->assertSee('+966 50 111 2222');
    }

    public function test_the_phone_is_scrubbed_and_forgotten_with_the_rest_of_the_traveller(): void
    {
        $this->assertSame('phone', Anonymisation::SCRUB['travellers']['phone'] ?? null);

        ['hassan' => $hassan, 'parents' => $parents] = $this->hassansFamily();
        $ahmed = $this->line($parents, 'Ahmed Ali')->traveller;
        $ahmed->update(['phone' => '+966 50 123 4567']);

        // Nobody is forgotten while a booking is live: they have travelled.
        Booking::query()->where('customer_id', $hassan->getKey())->where('status', Booking::CONFIRMED)->update(['status' => Booking::COMPLETED]);

        $this->artisan('data:forget', ['customer' => $hassan->getKey()])->assertSuccessful();

        $this->assertNotSame('+966 50 123 4567', $ahmed->fresh()->phone);
    }
}
