<?php

namespace Tests\Feature;

use App\Filament\Pages\Lists;
use App\Filament\Pages\Scan;
use App\Filament\Resources\BusGroups\BusGroupResource;
use App\Filament\Resources\BusGroups\Pages\ListBusGroups;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\BusGroup;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Operations\Buses;
use App\Services\Operations\Cards;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screens of batch A3: Buses, Lists and Scan.
 *
 * Rendered through Livewire rather than status-checked: a Filament page
 * answers 200 while a column closure throws in its own request.
 */
class GroundOperationsAdminTest extends TestCase
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

    private function party(Departure $departure, int $people, string $name = 'Aminath Ali'): Booking
    {
        $customer = Customer::factory()->create(['name' => $name, 'phone' => '7771234']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => $people,
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        for ($i = 1; $i <= $people; $i++) {
            $traveller = Traveller::factory()->for($customer)->create(['full_name' => $name.' '.$i]);
            $booking->travellers()->create(['traveller_id' => $traveller->getKey(), 'occupancy' => 'quad', 'is_lead' => $i === 1]);
        }

        return $booking->fresh(['travellers.traveller']);
    }

    public function test_who_may_open_buses_lists_and_scan(): void
    {
        $this->departure();

        $expect = [
            Access::OPERATIONS_MANAGER => [200, 200, 200],
            Access::TOUR_LEADER => [200, 200, 200],
            Access::BOOKING_STAFF => [200, 200, 200],
            Access::FINANCE => [403, 200, 403],
            Access::VISA_STAFF => [403, 200, 403],
            Access::PILGRIM_SUPPORT => [200, 403, 403],
            Access::CONTENT_MANAGER => [403, 403, 403],
            Access::REPORTING => [403, 403, 403],
        ];

        foreach ($expect as $role => [$buses, $lists, $scan]) {
            $user = $this->staff($role);
            $this->actingAs($user)->get(BusGroupResource::getUrl())->assertStatus($buses, $role.' on Buses');
            $this->actingAs($user)->get(Lists::getUrl())->assertStatus($lists, $role.' on Lists');
            $this->actingAs($user)->get(Scan::getUrl())->assertStatus($scan, $role.' on Scan');
        }
    }

    public function test_operations_makes_a_bus_and_puts_people_on_it_from_the_screen(): void
    {
        $departure = $this->departure();
        $booking = $this->party($departure, 2);
        [$first, $second] = $booking->travellers->all();

        $page = Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))->test(ListBusGroups::class);

        $page->callAction('create', data: [
            'departure_id' => $departure->getKey(),
            'name' => 'Bus 1',
            'capacity' => 1,
            'leader_name' => 'Ahmed',
            'leader_phone' => '7779999',
            'sort_order' => 0,
        ])->assertHasNoActionErrors();

        $bus = BusGroup::query()->sole();
        $this->assertSame('Bus 1', $bus->name);

        // The same name twice on one departure is refused; on another it is fine.
        $page->callAction('create', data: ['departure_id' => $departure->getKey(), 'name' => 'Bus 1', 'sort_order' => 0])
            ->assertHasActionErrors(['name'])
            ->unmountAction();

        $page->callTableAction('assign', $bus, data: ['ids' => [$first->getKey(), $second->getKey()]])
            ->assertHasNoTableActionErrors()
            ->assertNotified('2 put on Bus 1');

        $this->assertSame($bus->getKey(), $first->fresh()->bus_group_id);
        $this->assertSame($bus->getKey(), $second->fresh()->bus_group_id);

        $page->assertCanSeeTableRecords([$bus])
            ->assertSee('2 of 1')
            ->assertSee('Aminath Ali 1');

        $page->callTableAction('board', $bus, data: ['id' => $first->getKey()])
            ->assertNotified('Aminath Ali 1 is on Bus 1');
        $this->assertTrue($first->fresh()->isCheckedIn());

        $page->callTableAction('remove', $bus, data: ['id' => $second->getKey()])
            ->assertNotified('Taken off Bus 1');
        $this->assertNull($second->fresh()->bus_group_id);

        $page->callTableAction('delete', $bus);
        $this->assertNull($first->fresh()->bus_group_id, 'A deleted bus leaves nobody on a coach that is gone.');
        $this->assertFalse($first->fresh()->isCheckedIn());
    }

    public function test_a_reader_sees_the_buses_and_none_of_the_buttons(): void
    {
        $departure = $this->departure();
        $this->party($departure, 1);
        $bus = BusGroup::create(['departure_id' => $departure->getKey(), 'name' => 'Bus 1']);
        app(Buses::class)->assign($bus, BookingTraveller::query()->pluck('id')->all());

        Livewire::actingAs($this->staff(Access::PILGRIM_SUPPORT))
            ->test(ListBusGroups::class)
            ->assertCanSeeTableRecords([$bus])
            ->assertSee('Aminath Ali 1')
            ->assertTableActionHidden('assign', $bus)
            ->assertTableActionHidden('board', $bus)
            ->assertTableActionHidden('list', $bus)
            ->assertTableActionHidden('tags', $bus);

        // The leader sees the list and the tick, not the seating.
        Livewire::actingAs($this->staff(Access::TOUR_LEADER))
            ->test(ListBusGroups::class)
            ->assertTableActionHidden('assign', $bus)
            ->assertTableActionVisible('board', $bus)
            ->assertTableActionVisible('list', $bus)
            ->assertTableActionHidden('tags', $bus);
    }

    public function test_the_lists_page_offers_what_the_reader_may_print(): void
    {
        $departure = $this->departure();
        $this->party($departure, 2);
        BusGroup::create(['departure_id' => $departure->getKey(), 'name' => 'Bus 1']);

        Livewire::actingAs($this->staff(Access::TOUR_LEADER))
            ->test(Lists::class)
            ->assertSet('departureId', $departure->getKey())
            ->assertSee('2 travellers')
            ->assertSee('Bus lists')
            ->assertSee('Bus 1')
            ->assertSee(route('lists.manifest', ['departure' => $departure, 'preset' => 'airline']))
            ->assertDontSee('Payment status')
            ->assertDontSee('Documents still owed')
            ->assertDontSee('Luggage tags');

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(Lists::class)
            ->assertSee('Payment status')
            ->assertSee(route('lists.payments', ['departure' => $departure]))
            ->assertDontSee('Documents still owed');

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(Lists::class)
            ->assertSee('Documents still owed')
            ->assertSee('Customer cards')
            ->assertSee('Luggage tags');

        // The departure can be named in the address, as the board links to it.
        $other = $this->departure();
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(Lists::getUrl(['departure' => $other->getKey()]))
            ->assertOk()
            ->assertSee('0 travellers');
    }

    public function test_the_scan_page_finds_a_card_and_checks_the_traveller_in(): void
    {
        $departure = $this->departure();
        $booking = $this->party($departure, 1);
        $member = $booking->travellers->first();
        $bus = BusGroup::create(['departure_id' => $departure->getKey(), 'name' => 'Bus 1']);
        app(Buses::class)->assign($bus, [$member->getKey()]);
        $card = app(Cards::class)->issue($member, Card::LUGGAGE);

        $leader = $this->staff(Access::TOUR_LEADER);

        $page = Livewire::actingAs($leader)->test(Scan::class)
            ->set('code', 'RIH-L-999999')
            ->call('lookup')
            ->assertSet('cardId', null)
            ->assertNotified('No card matches that');

        $page->set('code', $card->serial)
            ->call('lookup')
            ->assertSet('cardId', $card->getKey())
            ->assertSee('Aminath Ali 1')
            ->assertSee('Bus 1')
            ->assertSee('Not yet')
            ->assertSee('Check in to Bus 1')
            ->assertDontSee('Money');

        $page->call('checkIn')->assertNotified('Aminath Ali 1 is on Bus 1');
        $this->assertTrue($member->fresh()->isCheckedIn());

        $page->call('checkIn')->assertNotified('Boarding undone');
        $this->assertFalse($member->fresh()->isCheckedIn());

        $page->call('clear')->assertSet('cardId', null)->assertSet('code', '');

        // The camera app opens the link; the page reads the token off it.
        Livewire::actingAs($leader)->withQueryParams(['t' => $card->qr_token])
            ->test(Scan::class)
            ->assertSet('cardId', $card->getKey())
            ->assertSee('Aminath Ali 1');

        // Booking staff scan and read, and cannot tick anybody on board.
        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(Scan::class)
            ->set('code', $card->serial)
            ->call('lookup')
            ->assertSee('Aminath Ali 1')
            ->assertDontSee('Check in to Bus 1')
            ->call('checkIn');
        $this->assertFalse($member->fresh()->isCheckedIn());
    }

    public function test_the_bottom_bar_offers_scan_to_the_leader_at_the_coach_door(): void
    {
        // Buses, Lists, Scan: the three things a leader does from a phone,
        // and the bar's minimum — with Scan alone there would be no bar.
        $this->actingAs($this->staff(Access::TOUR_LEADER))
            ->get(Scan::getUrl())
            ->assertOk()
            ->assertSee('<span class="rihla-bottom-nav__label">Buses</span>', false)
            ->assertSee('<span class="rihla-bottom-nav__label">Lists</span>', false)
            ->assertSee('<span class="rihla-bottom-nav__label">Scan</span>', false);

        // The office has five busier things on its bar.
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(Scan::getUrl())
            ->assertOk()
            ->assertDontSee('<span class="rihla-bottom-nav__label">Scan</span>', false);
    }
}
