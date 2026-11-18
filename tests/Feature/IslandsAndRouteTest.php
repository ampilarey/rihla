<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureFlight;
use App\Models\DepartureHotel;
use App\Models\Island;
use App\Models\Person;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Operations\Lists;
use App\Support\Access;
use App\Support\DepartureRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3d of docs/UMRAH_COMPANIES_PLAN.md, from Hijaz: an island list for
 * addresses, and the route — where a departure is on each day.
 */
class IslandsAndRouteTest extends TestCase
{
    use RefreshDatabase;

    private function island(string $name, ?string $atoll = null): Island
    {
        return Island::query()->where('name', $name)->when($atoll, fn ($q) => $q->where('atoll_code', $atoll))->sole();
    }

    public function test_the_islands_arrive_with_the_migration_because_nothing_seeds_production(): void
    {
        $this->assertSame(192, Island::query()->count());
        $this->assertSame(192, Island::query()->active()->count());
        $this->assertSame('HDh. Kulhudhuffushi', $this->island('Kulhudhuffushi')->label());
    }

    /** Through the guarded path: a factory would set a column `$fillable` forgot. */
    public function test_staff_put_a_customer_on_an_island_from_the_form(): void
    {
        $customer = Customer::factory()->create();
        $island = $this->island('Kulhudhuffushi');

        Livewire::actingAs(User::factory()->create()->assignRole(Access::SUPER_ADMIN))
            ->test(ViewCustomer::class, ['record' => $customer->getKey()])
            ->callAction('edit', data: ['island_id' => $island->id])
            ->assertHasNoActionErrors();

        $this->assertSame($island->id, $customer->fresh()->island_id);
    }

    public function test_the_ministry_list_names_each_travellers_island_or_else_the_bookers(): void
    {
        $departure = Departure::factory()->withSeats(10)->create(['date_start' => now()->addMonth(), 'date_end' => now()->addMonth()->addDays(14)]);
        $customer = Customer::factory()->create(['island_id' => $this->island('Kulhudhuffushi')->id]);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'departure_id' => $departure->id, 'seats' => 2]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        $own = Traveller::factory()->for($customer)->create(['full_name' => 'Lives Elsewhere']);
        $own->update(['island_id' => $this->island('Hithadhoo', 'S')->id]);
        $home = Traveller::factory()->for($customer)->create(['full_name' => 'Lives At Home']);
        $booking->travellers()->create(['traveller_id' => $own->id, 'occupancy' => 'quad', 'is_lead' => true]);
        $booking->travellers()->create(['traveller_id' => $home->id, 'occupancy' => 'quad']);

        $list = app(Lists::class)->manifest($departure, 'ministry');
        $byName = collect($list->rows)->mapWithKeys(fn (array $row): array => [explode(' (', $row['name'])[0] => $row['island']]);

        $this->assertArrayHasKey('island', $list->columns);
        $this->assertSame('S. Hithadhoo', $byName['Lives Elsewhere']);
        $this->assertSame('HDh. Kulhudhuffushi', $byName['Lives At Home']);
    }

    private function departureWithRoute(string $homeFrom = 'MED'): Departure
    {
        $departure = Departure::factory()->withSeats(10)->create(['date_start' => '2027-01-10', 'date_end' => '2027-01-20']);

        DepartureFlight::create([
            'departure_id' => $departure->id, 'direction' => DepartureFlight::OUTBOUND, 'airline' => 'Saudia', 'flight_number' => 'SV 3301',
            'from_airport' => 'MLE', 'to_airport' => 'JED', 'departs_at' => '2027-01-10 23:30', 'arrives_at' => '2027-01-11 04:10',
        ]);
        DepartureFlight::create([
            'departure_id' => $departure->id, 'direction' => DepartureFlight::RETURN, 'airline' => 'Saudia', 'flight_number' => 'SV 3302',
            'from_airport' => $homeFrom, 'to_airport' => 'MLE', 'departs_at' => '2027-01-20 11:00',
        ]);

        $makkah = DepartureHotel::create(['departure_id' => $departure->id, 'city' => DepartureHotel::CITY_MAKKAH, 'name' => 'Swissôtel Al Maqam', 'nights' => 5, 'sort_order' => 1]);
        $makkah->update(['leaves_by' => 'train']);
        DepartureHotel::create(['departure_id' => $departure->id, 'city' => DepartureHotel::CITY_MADINAH, 'name' => 'Pullman Zamzam', 'nights' => 4, 'sort_order' => 2]);

        return $departure->fresh();
    }

    public function test_the_route_follows_the_flights_and_the_hotels_in_their_order_and_nights(): void
    {
        $stops = DepartureRoute::stops($this->departureWithRoute());

        $this->assertSame(['Jeddah', 'Makkah', 'Madinah'], array_column($stops, 'place'));
        $this->assertSame('2027-01-11', $stops[0]['arrives']?->toDateString(), 'The day the flight lands, not the day it leaves Malé.');
        $this->assertSame(['2027-01-11', '2027-01-16'], [$stops[1]['arrives']?->toDateString(), $stops[1]['leaves']?->toDateString()]);
        $this->assertSame('Haramain train', $stops[1]['by'], 'Stored on the hotel, through its fillable.');
        $this->assertSame(['2027-01-16', '2027-01-20'], [$stops[2]['arrives']?->toDateString(), $stops[2]['leaves']?->toDateString()]);
        $this->assertSame('Coach to the airport', $stops[2]['by']);

        // Flying home from Jeddah adds the last day there.
        $fromJeddah = DepartureRoute::stops($this->departureWithRoute('JED'));
        $this->assertSame(['Jeddah', 'Makkah', 'Madinah', 'Jeddah'], array_column($fromJeddah, 'place'));
        $this->assertSame('Flight SV 3302', $fromJeddah[3]['by']);
    }

    public function test_a_route_with_no_dates_yet_says_so_rather_than_guessing(): void
    {
        $departure = Departure::factory()->withSeats(10)->create(['date_start' => null, 'date_end' => null]);
        DepartureHotel::create(['departure_id' => $departure->id, 'city' => DepartureHotel::CITY_MAKKAH, 'name' => 'Hotel', 'nights' => 5]);

        $stops = DepartureRoute::stops($departure);

        $this->assertCount(1, $stops);
        $this->assertNull($stops[0]['arrives']);
        $this->assertNull($stops[0]['leaves']);
    }

    public function test_the_tour_leader_sees_the_route(): void
    {
        URL::defaults(['locale' => 'en']);
        $departure = $this->departureWithRoute();
        $person = Person::factory()->create();
        $departure->forceFill(['tour_leader_id' => $person->id, 'date_start' => now()->subDay(), 'date_end' => now()->addDays(9)])->save();
        $leader = User::factory()->create()->assignRole(Access::TOUR_LEADER);
        $person->forceFill(['user_id' => $leader->id])->save();

        $this->actingAs($leader->fresh())
            ->get(route('leader.departure', $departure))
            ->assertOk()
            ->assertSee('Route')
            ->assertSee('Pullman Zamzam')
            ->assertSee('then Haramain train');
    }
}
