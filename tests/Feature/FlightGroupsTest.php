<?php

namespace Tests\Feature;

use App\Filament\Resources\BusGroups\Pages\ListBusGroups;
use App\Filament\Resources\FlightGroups\Pages\ListFlightGroups;
use App\Models\Booking;
use App\Models\BusGroup;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureFlight;
use App\Models\FlightGroup;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Operations\Buses;
use App\Services\Operations\FlightGroups;
use App\Services\Operations\Lists;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Flight groups (companies plan, Phase 3c, from Hijaz): the people of a
 * departure on one flight out and one home, each with its own buses.
 */
class FlightGroupsTest extends TestCase
{
    use RefreshDatabase;

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(40)->create([
            'date_start' => now()->addDays(30),
            'date_end' => now()->addDays(44),
        ]);
    }

    private function party(Departure $departure, int $people, string $name): Booking
    {
        $customer = Customer::factory()->create(['name' => $name]);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => $people,
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        for ($i = 1; $i <= $people; $i++) {
            $traveller = Traveller::factory()->for($customer)->create(['full_name' => $name.' '.$i, 'passport_number' => 'P'.random_int(100000, 999999)]);
            $booking->travellers()->create(['traveller_id' => $traveller->getKey(), 'occupancy' => 'quad', 'is_lead' => $i === 1]);
        }

        return $booking->fresh(['travellers.traveller', 'customer']);
    }

    private function flight(Departure $departure, string $direction, string $number, string $when): DepartureFlight
    {
        return DepartureFlight::create([
            'departure_id' => $departure->getKey(), 'direction' => $direction, 'airline' => 'Saudia',
            'flight_number' => $number, 'from_airport' => 'MLE', 'to_airport' => 'JED', 'departs_at' => $when,
        ]);
    }

    private function group(Departure $departure, string $name, ?DepartureFlight $out = null): FlightGroup
    {
        return FlightGroup::create(['departure_id' => $departure->getKey(), 'name' => $name, 'outbound_flight_id' => $out?->getKey()]);
    }

    public function test_moving_somebody_to_another_group_takes_them_off_the_old_groups_bus(): void
    {
        $departure = $this->departure();
        $a = $this->group($departure, 'Group A');
        $b = $this->group($departure, 'Group B');
        [$first, $second] = $this->party($departure, 2, 'Aminath Ali')->travellers->all();
        $elsewhere = $this->party($this->departure(), 1, 'Mariyam Hassan')->travellers->first();

        $result = app(FlightGroups::class)->assign($a, [$first->id, $second->id, $elsewhere->id]);
        $this->assertSame(2, $result['assigned']);
        $this->assertSame(['Mariyam Hassan 1: on another departure'], $result['skipped']);
        $this->assertNull($elsewhere->fresh()->flight_group_id);

        $busA = BusGroup::create(['departure_id' => $departure->id, 'flight_group_id' => $a->id, 'name' => 'Bus 1', 'colour' => '#16a34a']);
        app(Buses::class)->assign($busA, [$first->id]);
        app(Buses::class)->toggleCheckIn($first->fresh());

        $moved = app(FlightGroups::class)->assign($b, [$first->id]);
        $this->assertSame(1, $moved['offBus']);
        $first->refresh();
        $this->assertSame($b->id, $first->flight_group_id);
        $this->assertNull($first->bus_group_id, 'Bus 1 meets the other flight.');
        $this->assertNull($first->checked_in_at);
    }

    public function test_a_bus_carries_one_group_and_puts_the_ungrouped_in_it(): void
    {
        $departure = $this->departure();
        $a = $this->group($departure, 'Group A');
        $b = $this->group($departure, 'Group B');
        [$inB, $nowhere] = $this->party($departure, 2, 'Aminath Ali')->travellers->all();
        app(FlightGroups::class)->assign($b, [$inB->id]);

        $bus = BusGroup::create(['departure_id' => $departure->id, 'flight_group_id' => $a->id, 'name' => 'Bus 1']);
        $result = app(Buses::class)->assign($bus, [$inB->id, $nowhere->id]);

        $this->assertSame(1, $result['assigned']);
        $this->assertSame(['Aminath Ali 1: in Group B, not Group A'], $result['skipped']);
        $this->assertNull($inB->fresh()->bus_group_id);
        $this->assertSame($a->id, $nowhere->fresh()->flight_group_id, 'Getting on a group\'s bus puts them in its group.');

        // A bus with no group takes anybody and changes nobody's group.
        $free = BusGroup::create(['departure_id' => $departure->id, 'name' => 'Bus 2']);
        app(Buses::class)->assign($free, [$inB->id]);
        $this->assertSame($b->id, $inB->fresh()->flight_group_id);
        $this->assertSame($free->id, $inB->fresh()->bus_group_id);
    }

    public function test_a_deleted_group_leaves_its_people_and_buses_on_the_departure(): void
    {
        $departure = $this->departure();
        $group = $this->group($departure, 'Group A');
        $member = $this->party($departure, 1, 'Aminath Ali')->travellers->first();
        $bus = BusGroup::create(['departure_id' => $departure->id, 'flight_group_id' => $group->id, 'name' => 'Bus 1']);
        app(Buses::class)->assign($bus, [$member->id]);

        $group->delete();

        $this->assertNull($member->fresh()->flight_group_id);
        $this->assertSame($bus->id, $member->fresh()->bus_group_id, 'Still on the bus; only the group is gone.');
        $this->assertNull($bus->fresh()->flight_group_id);
        $this->assertSame($departure->company_id, $group->company_id, 'The group took its departure\'s company.');
    }

    public function test_the_airline_list_prints_one_group_and_the_office_list_names_each(): void
    {
        $departure = $this->departure();
        $out = $this->flight($departure, DepartureFlight::OUTBOUND, 'SV 3301', now()->addDays(30)->toDateTimeString());
        $a = $this->group($departure, 'Group A', $out);
        $b = $this->group($departure, 'Group B');
        $ali = $this->party($departure, 2, 'Aminath Ali');
        $hassan = $this->party($departure, 1, 'Mariyam Hassan');
        app(FlightGroups::class)->assign($a, $ali->travellers->modelKeys());
        app(FlightGroups::class)->assign($b, $hassan->travellers->modelKeys());

        $airline = app(Lists::class)->manifest($departure, 'airline', group: $a);
        $this->assertCount(2, $airline->rows);
        $this->assertStringContainsString('Group A · SV 3301', $airline->subtitle);
        $this->assertArrayNotHasKey('group', $airline->columns, 'One group: the column would say the same thing on every row.');

        $office = app(Lists::class)->manifest($departure, 'office');
        $this->assertArrayHasKey('group', $office->columns);
        $this->assertEqualsCanonicalizing(['Group A', 'Group A', 'Group B'], array_column($office->rows, 'group'));

        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
        $csv = $this->actingAs($admin)
            ->get(route('lists.manifest', ['departure' => $departure->id, 'preset' => 'airline', 'group' => $a->id, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Aminath Ali 1', $csv);
        $this->assertStringNotContainsString('Mariyam Hassan', $csv);

        $other = $this->group($this->departure(), 'Group A');
        $this->actingAs($admin)
            ->get(route('lists.manifest', ['departure' => $departure->id, 'preset' => 'airline', 'group' => $other->id]))
            ->assertNotFound();
    }

    public function test_operations_makes_a_group_and_fills_it_from_the_screen(): void
    {
        $departure = $this->departure();
        $out = $this->flight($departure, DepartureFlight::OUTBOUND, 'SV 3301', now()->addDays(30)->toDateTimeString());
        $ali = $this->party($departure, 2, 'Aminath Ali');
        [$first, $second] = $ali->travellers->all();

        $page = Livewire::actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))->test(ListFlightGroups::class);

        $page->callAction('create', data: [
            'departure_id' => $departure->id,
            'name' => 'Group A',
            'outbound_flight_id' => $out->id,
            'sort_order' => 0,
        ])->assertHasNoActionErrors();

        $group = FlightGroup::query()->sole();
        $this->assertSame($out->id, $group->outbound_flight_id);

        $page->callAction('create', data: ['departure_id' => $departure->id, 'name' => 'Group A', 'sort_order' => 0])
            ->assertHasActionErrors(['name'])
            ->unmountAction();

        // One chosen, the family comes along.
        $page->callTableAction('assign', $group, data: ['ids' => [$first->id], 'with_family' => true])
            ->assertHasNoTableActionErrors()
            ->assertNotified('2 put in Group A');
        $this->assertSame($group->id, $second->fresh()->flight_group_id);

        $page->assertCanSeeTableRecords([$group])->assertSee('SV 3301');

        // Its bus, in a colour, from the bus screen.
        Livewire::actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))
            ->test(ListBusGroups::class)
            ->callAction('create', data: [
                'departure_id' => $departure->id, 'flight_group_id' => $group->id,
                'name' => 'Bus 1', 'colour' => '#16a34a', 'sort_order' => 0,
            ])->assertHasNoActionErrors();
        $this->assertSame($group->id, BusGroup::query()->sole()->flight_group_id);
        $this->assertSame('#16a34a', BusGroup::query()->sole()->colour);

        $page->callTableAction('remove', $group, data: ['id' => $second->id])->assertNotified('Taken out of Group A');
        $this->assertNull($second->fresh()->flight_group_id);
    }

    public function test_a_reader_of_buses_sees_the_groups_and_none_of_the_buttons(): void
    {
        $departure = $this->departure();
        $group = $this->group($departure, 'Group A');
        $reader = User::factory()->create();
        $reader->givePermissionTo(['bus.viewAny', 'bus.view']);

        Livewire::actingAs($reader)->test(ListFlightGroups::class)
            ->assertCanSeeTableRecords([$group])
            ->assertActionHidden('create')
            ->assertTableActionHidden('assign', $group)
            ->assertTableActionHidden('edit', $group)
            ->assertTableActionHidden('delete', $group);
    }
}
