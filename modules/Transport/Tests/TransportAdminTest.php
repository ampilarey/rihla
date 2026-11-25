<?php

namespace Modules\Transport\Tests;

use App\Models\Island;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Transport\Filament\Resources\BoatBookings\Pages\ManageBoatBookings;
use Modules\Transport\Filament\Resources\BoatRoutes\Pages\ManageBoatRoutes;
use Modules\Transport\Filament\Resources\Sailings\Pages\ManageSailings;
use Modules\Transport\Filament\Resources\Vessels\Pages\ManageVessels;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Models\Vessel;
use Tests\TestCase;

/**
 * The staff screens of Transport T1: the Operations Manager sets the
 * timetable, Booking Staff book a caller, and nobody else sees it.
 */
class TransportAdminTest extends TestCase
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
        [$from, $to] = Island::query()->orderBy('id')->limit(2)->get()->all();
        $this->boat = Vessel::create(['partner_id' => $rihla->id, 'name' => 'Rihla One', 'kind' => Vessel::SPEEDBOAT, 'seats' => 12]);
        $this->route = BoatRoute::create(['partner_id' => $rihla->id, 'origin_island_id' => $from->id, 'destination_island_id' => $to->id, 'duration_minutes' => 60, 'adult_fare_minor' => 30000]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_the_operations_manager_adds_a_timetable(): void
    {
        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))->test(ManageSailings::class)
            ->callAction('timetable', data: [
                'boat_route_id' => $this->route->id,
                'vessel_id' => $this->boat->id,
                'from' => '2026-11-30',
                'to' => '2026-12-06',
                'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'times' => ['07:30'],
            ])
            ->assertNotified('7 sailings added');

        $this->assertSame(7, Sailing::count());
    }

    public function test_booking_staff_book_a_caller_but_cannot_change_the_timetable(): void
    {
        $sailing = Sailing::create(['boat_route_id' => $this->route->id, 'vessel_id' => $this->boat->id, 'departs_at' => now()->addDay(), 'seats_total' => 12]);
        $desk = $this->staff(Access::BOOKING_STAFF);

        Livewire::actingAs($desk)->test(ManageSailings::class)
            ->assertActionHidden('timetable')
            ->callTableAction('book', $sailing, data: [
                'contact_name' => 'Mariyam',
                'contact_phone' => '7779999',
                'passengers' => [['full_name' => 'Mariyam Hassan', 'is_child' => false], ['full_name' => 'Ahmed Hassan', 'is_child' => true]],
            ])
            ->assertNotified();

        $booking = BoatBooking::query()->sole();
        $this->assertSame(2, $booking->seats);
        $this->assertSame($desk->id, $booking->booked_by);

        Livewire::actingAs($desk)->test(ManageBoatBookings::class)
            ->assertCanSeeTableRecords([$booking])
            ->callTableAction('confirm', $booking);
        $this->assertSame(BoatBooking::CONFIRMED, $booking->fresh()->status);
    }

    public function test_a_role_without_transport_sees_none_of_it(): void
    {
        $this->actingAs($this->staff(Access::TOUR_LEADER))
            ->get('/staff/sailings')->assertForbidden();
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get('/staff/sailings')->assertOk();
    }

    /**
     * Filament 5 authorizes a page's create and edit buttons through the
     * policy and never asked the resources' own canCreate(): Booking Staff
     * were shown, and could use, "Add a boat", a route and a sailing.
     */
    public function test_only_the_timetable_owner_can_add_or_edit_boats_routes_and_sailings(): void
    {
        $desk = $this->staff(Access::BOOKING_STAFF);
        $manager = $this->staff(Access::OPERATIONS_MANAGER);

        foreach ([ManageVessels::class, ManageBoatRoutes::class, ManageSailings::class] as $page) {
            Livewire::actingAs($desk)->test($page)->assertActionHidden('create');
            Livewire::actingAs($manager)->test($page)->assertActionVisible('create');
        }

        Livewire::actingAs($desk)->test(ManageVessels::class)->assertTableActionHidden('edit', $this->boat);
        Livewire::actingAs($manager)->test(ManageVessels::class)->assertTableActionVisible('edit', $this->boat);
    }
}
