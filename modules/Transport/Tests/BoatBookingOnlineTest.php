<?php

namespace Modules\Transport\Tests;

use App\Models\Island;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use App\Support\Services;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Telegram\Models\TelegramLink;
use Modules\Transport\Http\Controllers\BoatController;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Models\Vessel;
use Tests\TestCase;

/**
 * Transport T2 (docs/TRANSPORT_PLAN.md): a visitor books seats without
 * phoning, and their QR tickets open from the random link they are given.
 */
class BoatBookingOnlineTest extends TestCase
{
    use RefreshDatabase;

    private Sailing $sailing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 06:00'));

        $rihla = Partner::rihla();
        $this->assertNotNull($rihla);
        [$male, $fulidhoo] = Island::query()->orderBy('id')->limit(2)->get()->all();
        $boat = Vessel::create(['partner_id' => $rihla->id, 'name' => 'Rihla One', 'kind' => Vessel::SPEEDBOAT, 'seats' => 3]);
        $route = BoatRoute::create([
            'partner_id' => $rihla->id, 'origin_island_id' => $male->id, 'destination_island_id' => $fulidhoo->id,
            'duration_minutes' => 90, 'currency' => 'MVR', 'adult_fare_minor' => 50000, 'child_fare_minor' => 25000,
        ]);
        $this->sailing = Sailing::create(['boat_route_id' => $route->id, 'vessel_id' => $boat->id, 'departs_at' => now()->addDay()->setTime(7, 30), 'seats_total' => 3]);
    }

    /** @return array<string, mixed> */
    private function form(array $passengers = [['full_name' => 'Aminath Ali'], ['full_name' => 'Ali Hassan', 'is_child' => '1']]): array
    {
        return ['contact_name' => 'Aminath Ali', 'contact_phone' => '7771234', 'passengers' => $passengers];
    }

    public function test_switched_off_the_boats_pages_do_not_exist(): void
    {
        $this->get('/en/boats')->assertNotFound();
        $this->get('/en/boats/sailings/'.$this->sailing->id.'/book')->assertNotFound();
    }

    public function test_coming_soon_shows_the_timetable_but_sells_nothing(): void
    {
        Services::save(['transport' => Services::COMING_SOON]);

        $this->get('/en/boats')->assertOk()
            ->assertSee('Online booking for boats is coming soon')
            ->assertSee('07:30')
            ->assertDontSee('/en/boats/sailings/'.$this->sailing->id.'/book');
        $this->get('/en/boats/sailings/'.$this->sailing->id.'/book')->assertNotFound();
        $this->post('/en/boats/sailings/'.$this->sailing->id.'/book', $this->form())->assertNotFound();
        $this->assertSame(0, BoatBooking::count());
    }

    public function test_a_visitor_books_two_seats_and_opens_their_qr_tickets(): void
    {
        Services::save(['transport' => Services::ON]);
        config(['payments.bank.accounts' => ['MVR' => '7730000123456']]);
        config(['telegram.bot_token' => '123456:RIHLArihlaRIHLArihlaRIHLArihla']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $desk = User::factory()->create()->assignRole(Access::BOOKING_STAFF);
        TelegramLink::create(['user_id' => $desk->id, 'chat_id' => 555, 'linked_at' => now()]);

        $this->get('/en/boats')->assertOk()->assertSee('3 seats left')->assertSee('Book seats');
        $this->get('/en/boats/sailings/'.$this->sailing->id.'/book')->assertOk()->assertSee('Passenger 1, full name');

        $response = $this->post('/en/boats/sailings/'.$this->sailing->id.'/book', $this->form());

        $booking = BoatBooking::query()->sole();
        $response->assertRedirect('/en/boats/tickets/'.$booking->tickets_key);
        $this->assertSame(BoatBooking::HELD, $booking->status);
        $this->assertSame(75000, $booking->total_minor);
        $this->assertSame(2, $this->sailing->fresh()->seats_taken);
        $this->assertTrue($booking->held_until->equalTo(now()->addMinutes(60)), 'An online booking holds for the online window.');

        $this->get((string) $response->headers->get('Location'))->assertOk()
            ->assertSee($booking->reference)
            ->assertSee('7730000123456')
            ->assertSee('Aminath Ali')
            ->assertSee('Ali Hassan')
            ->assertSee('data:image/png;base64,', false);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && (int) $request['chat_id'] === 555 && str_contains((string) $request['text'], $booking->reference));
    }

    public function test_the_tickets_page_is_found_by_its_random_key_never_by_the_reference(): void
    {
        Services::save(['transport' => Services::ON]);
        $this->post('/en/boats/sailings/'.$this->sailing->id.'/book', $this->form());
        $booking = BoatBooking::query()->sole();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', (string) $booking->tickets_key);
        $this->get('/en/boats/tickets/'.$booking->reference)->assertNotFound();
        $this->get('/en/boats/tickets/'.str_repeat('a', 40))->assertNotFound();
        $this->get(BoatController::ticketUrl($booking))->assertOk();
    }

    public function test_more_passengers_than_seats_left_is_refused_with_a_message_and_a_bot_is_ignored(): void
    {
        Services::save(['transport' => Services::ON]);
        $four = array_fill(0, 4, ['full_name' => 'Somebody']);

        $this->from('/en/boats/sailings/'.$this->sailing->id.'/book')
            ->post('/en/boats/sailings/'.$this->sailing->id.'/book', $this->form($four))
            ->assertRedirect('/en/boats/sailings/'.$this->sailing->id.'/book')
            ->assertSessionHasErrors(['passengers' => 'Only 3 seats are left on that sailing.']);

        $this->post('/en/boats/sailings/'.$this->sailing->id.'/book', $this->form() + ['website' => 'http://spam.example'])
            ->assertRedirect();
        $this->assertSame(0, BoatBooking::count());
        $this->assertSame(0, $this->sailing->fresh()->seats_taken);
    }

    public function test_the_hold_never_outlives_the_sailing(): void
    {
        Services::save(['transport' => Services::ON]);
        $this->travelTo($this->sailing->departs_at->copy()->subMinutes(20));

        $this->post('/en/boats/sailings/'.$this->sailing->id.'/book', $this->form());

        $this->assertTrue(BoatBooking::query()->sole()->held_until->equalTo($this->sailing->departs_at));
    }
}
