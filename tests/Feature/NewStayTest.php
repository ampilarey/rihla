<?php

namespace Tests\Feature;

use App\Filament\Pages\NewStay;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The office takes a booking by phone — §16 Phase 13.4.
 */
class NewStayTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2027, 1, 10));

        $this->property = Property::factory()->create([
            'currency' => 'USD',
            'min_nights' => 1,
            'partner_id' => Partner::factory()->commissionBased(15)->create()->id,
        ]);

        $this->room = RoomType::factory()->create([
            'property_id' => $this->property->id,
            'quantity' => 1,
            'sleeps' => 2,
            'base_rate_minor' => 10000,
        ]);
    }

    private function staff(string $role = Access::OPERATIONS_MANAGER): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'property_id' => $this->property->id,
            'room_type_id' => $this->room->id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
            'adults' => 2,
            'children' => 0,
            'audience' => 'tourist',
            'name' => 'Aminath Caller',
            'phone' => '+960 777 0000',
            'source' => 'phone',
        ];
    }

    public function test_the_page_is_for_whoever_may_take_bookings(): void
    {
        $this->assertTrue($this->staff()->can('stay.book'));

        $this->actingAs($this->staff())->get(NewStay::getUrl())->assertOk();
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))->get(NewStay::getUrl())->assertForbidden();
    }

    /** Office-made: no commission (ADR 0008 decision 2), and it says who made it. */
    public function test_a_phone_booking_is_made_through_the_booking_flow(): void
    {
        $staff = $this->staff();

        Livewire::actingAs($staff)
            ->test(NewStay::class)
            ->fillForm($this->form(['special_requests' => 'Arriving on the 16:00 ferry.']))
            ->call('save')
            ->assertHasNoFormErrors();

        $stay = Stay::sole();

        $this->assertSame(Stay::REQUESTED, $stay->status);
        $this->assertSame(Stay::VIA_STAFF, $stay->created_via);
        $this->assertSame($staff->id, $stay->created_by);
        $this->assertSame('phone', $stay->source);
        $this->assertSame(20000, $stay->total_minor);
        $this->assertNull($stay->commission_pct_snapshot);
        $this->assertSame('Aminath Caller', $stay->customer->name);
        $this->assertSame('Arriving on the 16:00 ferry.', $stay->special_requests);
    }

    public function test_an_existing_customer_can_be_chosen(): void
    {
        $customer = Customer::factory()->create();

        Livewire::actingAs($this->staff())
            ->test(NewStay::class)
            ->fillForm($this->form(['customer_id' => $customer->id, 'name' => null, 'phone' => null]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Stay::sole()->customer->is($customer));
        $this->assertSame(1, Customer::count());
    }

    public function test_the_host_already_said_yes_so_the_dates_are_taken_now(): void
    {
        Livewire::actingAs($this->staff())
            ->test(NewStay::class)
            ->fillForm($this->form(['hold_now' => true]))
            ->call('save');

        $this->assertSame(Stay::HELD, Stay::sole()->status);
    }

    /** Refused by the same availability check — and nothing left behind. */
    public function test_a_room_that_is_taken_is_refused_and_no_customer_is_left(): void
    {
        Stay::factory()->create([
            'property_id' => $this->property->id,
            'room_type_id' => $this->room->id,
            'check_in' => '2027-03-01',
            'check_out' => '2027-03-10',
            'status' => Stay::CONFIRMED,
        ]);
        $before = Customer::count();

        Livewire::actingAs($this->staff())
            ->test(NewStay::class)
            ->fillForm($this->form())
            ->call('save')
            ->assertNotified('Not free for those dates');

        $this->assertSame(1, Stay::count());
        $this->assertSame($before, Customer::count());
    }

    public function test_a_new_guest_needs_a_name_and_a_phone(): void
    {
        Livewire::actingAs($this->staff())
            ->test(NewStay::class)
            ->fillForm($this->form(['name' => null, 'phone' => null]))
            ->call('save')
            ->assertHasFormErrors(['name' => 'required', 'phone' => 'required']);

        $this->assertSame(0, Stay::count());
    }
}
