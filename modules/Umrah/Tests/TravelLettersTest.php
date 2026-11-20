<?php

namespace Modules\Umrah\Tests;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FlightGroup;
use App\Models\User;
use App\Services\Operations\FlightGroups;
use App\Services\Portal\Gatekeeper;
use App\Support\Access;
use App\Support\DepartureRoute;
use App\Support\SendToCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\DepartureFlight;
use Modules\Umrah\Models\Traveller;
use Tests\TestCase;

/**
 * Travel letters and "send to customer" (companies plan, Phase 3h, from
 * Hijaz): a page per traveller, with passport numbers for the office and
 * without them in the portal; the portal link ready for WhatsApp, Viber or
 * Telegram.
 */
class TravelLettersTest extends TestCase
{
    use RefreshDatabase;

    private function booking(string $name = 'Aminath Ali', string $passport = 'LA1234567', ?Departure $departure = null): Booking
    {
        $departure ??= Departure::factory()->withSeats(20)->create(['date_start' => now()->addDays(30), 'date_end' => now()->addDays(44)]);
        $customer = Customer::factory()->create(['name' => $name, 'phone' => '7771234']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'departure_id' => $departure->id, 'seats' => 1]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();
        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create(['full_name' => $name, 'passport_number' => $passport])->id,
            'occupancy' => 'quad',
        ]);

        return $booking->fresh();
    }

    private function html(Booking $booking, bool $withPassport): string
    {
        $booking->load(['departure.package', 'departure.flights', 'travellers.traveller', 'travellers.booking.customer', 'customer']);

        return view('pdf.travel-letter', [
            'departure' => $booking->departure,
            'members' => $booking->travellers,
            'route' => DepartureRoute::stops($booking->departure),
            'withPassport' => $withPassport,
            'issuer' => ['name' => 'Rihla Travels', 'address' => null, 'registration' => null, 'phone' => '7972434', 'email' => null],
        ])->render();
    }

    public function test_the_office_copy_carries_the_passport_and_the_portal_copy_does_not(): void
    {
        $booking = $this->booking();
        DepartureFlight::create([
            'departure_id' => $booking->departure_id, 'direction' => DepartureFlight::OUTBOUND, 'airline' => 'Saudia',
            'flight_number' => 'SV 3301', 'from_airport' => 'MLE', 'to_airport' => 'JED', 'departs_at' => now()->addDays(30),
        ]);

        $office = $this->html($booking, withPassport: true);
        $this->assertStringContainsString('Dear Aminath Ali', $office);
        $this->assertStringContainsString('LA1234567', $office);
        $this->assertStringContainsString('SV 3301', $office);

        $portal = $this->html($booking, withPassport: false);
        $this->assertStringContainsString('Dear Aminath Ali', $portal);
        $this->assertStringNotContainsString('LA1234567', $portal);
    }

    public function test_staff_print_a_bookings_letters_and_the_print_is_audited(): void
    {
        $booking = $this->booking();
        $staff = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);

        $this->actingAs($staff)->get(route('lists.letters', ['booking' => $booking->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::DOWNLOADED)->where('auditable_id', $booking->id)->count());

        $nobody = User::factory()->create();
        $this->actingAs($nobody)->get(route('lists.letters', ['booking' => $booking->id]))->assertForbidden();
    }

    public function test_a_flight_groups_letters_cover_its_people_across_bookings(): void
    {
        $first = $this->booking('Aminath Ali', 'LA1111111');
        $second = $this->booking('Mariyam Hassan', 'LA2222222', $first->departure);
        $group = FlightGroup::create(['departure_id' => $first->departure_id, 'name' => 'Group A']);
        $empty = FlightGroup::create(['departure_id' => $first->departure_id, 'name' => 'Group B']);
        app(FlightGroups::class)->assign($group, [...$first->travellers->modelKeys(), ...$second->travellers->modelKeys()]);

        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
        $this->actingAs($admin)->get(route('lists.group-letters', ['flightGroup' => $group->id]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($admin)->get(route('lists.group-letters', ['flightGroup' => $empty->id]))->assertNotFound();
    }

    public function test_the_portal_offers_the_letters_once_the_booking_is_confirmed(): void
    {
        $booking = $this->booking();
        $token = app(Gatekeeper::class)->issue($booking);
        $this->get("/en/portal/enter/{$token}");

        $this->get('/en/portal')->assertOk()->assertSee('Download the travel letters');
        $this->get('/en/portal/letters')->assertOk()->assertHeader('content-type', 'application/pdf');

        $booking->forceFill(['status' => Booking::HELD])->save();
        $this->get('/en/portal')->assertOk()->assertDontSee('Download the travel letters');
        $this->get('/en/portal/letters')->assertNotFound();
    }

    public function test_send_to_customer_builds_a_link_for_each_app(): void
    {
        $links = SendToCustomer::links('777-1234', 'Your booking RIH-B-2027-0001:', 'https://rihla.mv/en/portal/enter/abc');

        $this->assertStringStartsWith('https://wa.me/9607771234?text=', $links['whatsapp']);
        $this->assertStringContainsString(rawurlencode('https://rihla.mv/en/portal/enter/abc'), $links['whatsapp']);
        $this->assertStringStartsWith('viber://forward?text=', $links['viber']);
        $this->assertStringStartsWith('https://t.me/share/url?url='.rawurlencode('https://rihla.mv/en/portal/enter/abc'), $links['telegram']);

        // No number: WhatsApp asks who to send it to, rather than guessing.
        $this->assertStringStartsWith('https://wa.me/?text=', SendToCustomer::links(null, 'Hello')['whatsapp']);
    }
}
