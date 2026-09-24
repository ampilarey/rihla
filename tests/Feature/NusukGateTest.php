<?php

namespace Tests\Feature;

use App\Filament\Pages\NusukGate;
use App\Filament\Resources\Packages\PackageResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\NusukPermit;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Nusuk gate's own screen — §5.4b, §8.3.
 *
 * The prerequisite dates were editable only inside a package's departure
 * form, and Visa Staff — the role that deals with Nusuk and holds
 * `departure.nusuk` — cannot open packages at all. The first test is that
 * gap, stated; the rest is the screen that closes it.
 */
class NusukGateTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays(30),
            'date_end' => now()->addDays(44),
        ]);
    }

    /** Why the screen exists. If this ever fails, the gap may have closed another way. */
    public function test_visa_staff_cannot_reach_the_package_form(): void
    {
        $this->actingAs($this->staff(Access::VISA_STAFF))
            ->get(PackageResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_visa_staff_open_the_gate(): void
    {
        $departure = $this->departure();

        $this->actingAs($this->staff(Access::VISA_STAFF))
            ->get(NusukGate::getUrl())
            ->assertOk();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(NusukGate::class)
            ->assertCanSeeTableRecords([$departure])
            ->assertSee('Not yet — blocks permits');
    }

    public function test_recording_both_clears_the_gate(): void
    {
        $departure = $this->departure();
        $this->assertNotSame([], NusukPermit::missingPrerequisites($departure));

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(NusukGate::class)
            ->callAction(TestAction::make('record')->table($departure), data: [
                'nusuk_accommodation_recorded_at' => '2027-02-01 10:00:00',
                'nusuk_transport_recorded_at' => '2027-02-02 11:30:00',
            ])
            ->assertHasNoActionErrors();

        $departure->refresh();

        $this->assertSame('2027-02-01 10:00', $departure->nusuk_accommodation_recorded_at->format('Y-m-d H:i'));
        $this->assertSame([], NusukPermit::missingPrerequisites($departure));
    }

    /** Clearing one puts the gate back, as the departure form always did. */
    public function test_clearing_one_closes_the_gate_again(): void
    {
        $departure = $this->departure();
        $departure->update([
            'nusuk_accommodation_recorded_at' => now(),
            'nusuk_transport_recorded_at' => now(),
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(NusukGate::class)
            ->callAction(TestAction::make('record')->table($departure), data: [
                'nusuk_accommodation_recorded_at' => null,
                'nusuk_transport_recorded_at' => now()->toDateTimeString(),
            ]);

        $this->assertSame(['accommodation'], NusukPermit::missingPrerequisites($departure->fresh()));
    }

    /** A dealing with a Saudi system is not the website's to assert. */
    public function test_the_content_manager_cannot_open_it(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(NusukGate::getUrl())
            ->assertForbidden();
    }

    public function test_the_permit_count_is_against_the_people_travelling(): void
    {
        $departure = $this->departure();
        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => 2,
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        $travellers = Traveller::factory()->for($customer)->count(2)->create();

        foreach ($travellers as $i => $traveller) {
            $booking->travellers()->create([
                'traveller_id' => $traveller->getKey(),
                'occupancy' => 'double',
                'is_lead' => $i === 0,
            ]);
        }

        $permit = NusukPermit::create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'kind' => NusukPermit::UMRAH,
        ]);
        $permit->forceFill(['status' => NusukPermit::ISSUED, 'issued_at' => now()])->save();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(NusukGate::class)
            ->assertSee('1 of 2');
    }

    public function test_the_badge_counts_departures_still_blocked(): void
    {
        $this->actingAs($this->staff(Access::VISA_STAFF));

        $this->departure();
        $clear = $this->departure();
        $clear->update(['nusuk_accommodation_recorded_at' => now(), 'nusuk_transport_recorded_at' => now()]);

        $this->assertSame('1', NusukGate::getNavigationBadge());
    }
}
