<?php

namespace Tests\Feature;

use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\MoneyChart;
use App\Filament\Widgets\SeatsChart;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Companies\AddCompany;
use App\Services\Payments\Gateways;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The home page's cards and charts (companies plan, Phase 3g, from Hijaz):
 * six figures and two charts, each behind its own permission.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedParty(Departure $departure, int $people): Booking
    {
        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'departure_id' => $departure->id, 'seats' => $people, 'total_minor' => 1_000_000]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        for ($i = 0; $i < $people; $i++) {
            $booking->travellers()->create(['traveller_id' => Traveller::factory()->for($customer)->create()->id, 'occupancy' => 'quad']);
        }

        return $booking;
    }

    public function test_the_whole_office_sees_every_card_and_both_charts(): void
    {
        $departure = Departure::factory()->withSeats(40)->create(['date_start' => now()->addDays(10), 'date_end' => now()->addDays(24)]);
        $booking = $this->confirmedParty($departure, 3);
        app(Gateways::class)->for(Payment::CASH)->start($booking, Money::ofMajor(500));

        $this->actingAs(User::factory()->create()->assignRole(Access::SUPER_ADMIN))
            ->get('/staff')
            ->assertOk()
            ->assertSee('Next departure')
            ->assertSee('In 10 days')
            ->assertSee('Seats filled')
            ->assertSee('Travellers confirmed')
            ->assertSee('Received this month')
            ->assertSee('Still owed')
            ->assertSee('Payments to check')
            ->assertSee('Money in and out')
            ->assertSee('Seats by departure');
    }

    public function test_each_card_shows_only_to_somebody_who_may_see_what_it_counts(): void
    {
        $desk = User::factory()->create();
        $desk->givePermissionTo(['admin.access', 'booking.viewAny']);

        $this->actingAs($desk);
        $html = Livewire::test(DashboardStats::class)->html();
        $this->assertStringContainsString('Travellers confirmed', $html);
        $this->assertStringNotContainsString('Received this month', $html, 'A desk that takes bookings never sees the money.');
        $this->assertStringNotContainsString('Payments to check', $html);
        $this->assertFalse(MoneyChart::canView());
        $this->assertFalse(SeatsChart::canView());

        $finance = User::factory()->create()->assignRole(Access::FINANCE);
        $this->actingAs($finance);
        $this->assertTrue(MoneyChart::canView());
        $html = Livewire::test(DashboardStats::class)->html();
        $this->assertStringContainsString('Received this month', $html);
        $this->assertStringContainsString('Payments to check', $html);

        // Somebody with none of it gets no cards at all, not an empty box.
        $nobody = User::factory()->create();
        $nobody->givePermissionTo(['admin.access']);
        $this->actingAs($nobody);
        $this->assertFalse(DashboardStats::canView());
    }

    public function test_a_companys_home_page_counts_only_its_own_records(): void
    {
        Notification::fake();
        $coral = app(AddCompany::class)('Coral Umrah', 'Aishath Coral', 'coral@example.com');
        $owner = User::where('email', 'coral@example.com')->sole();

        // Rihla's own departure, with a booking, which Coral must not count.
        $this->confirmedParty(Departure::factory()->withSeats(40)->create(['date_start' => now()->addDays(10), 'date_end' => now()->addDays(20)]), 5);

        // Coral's, made as Coral.
        app(CompanyContext::class)->as($coral->id, function (): void {
            $this->confirmedParty(Departure::factory()->withSeats(20)->create(['date_start' => now()->addDays(30), 'date_end' => now()->addDays(40)]), 2);
        });

        $this->actingAs($owner)
            ->get('/company/'.$coral->slug)
            ->assertOk()
            ->assertSee('signed in as Owner')
            ->assertSee('Travellers confirmed')
            ->assertSee('In 30 days')
            ->assertDontSee('In 10 days')
            ->assertSee('of 20')
            ->assertDontSee('of 60', false);

    }
}
