<?php

namespace Modules\Umrah\Tests;

use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Services\Portal\Gatekeeper;
use Tests\TestCase;

/**
 * The customer portal with a password (companies plan, Phase 3h, from
 * Hijaz): chosen once from a booking link by somebody who knows the number
 * on file, signed in with by mobile number, and opening every booking of
 * that customer and nobody else's.
 */
class PortalPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $phone = '7771234'): Customer
    {
        return Customer::factory()->create(['phone' => $phone, 'name' => 'Aminath Ali']);
    }

    private function booking(Customer $customer, string $title = 'Ramadan 14 Nights'): Booking
    {
        $departure = Departure::factory()->withSeats(10)->create(['date_start' => now()->addDays(60), 'date_end' => now()->addDays(74)]);
        $departure->package->update(['title' => ['en' => $title]]);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'departure_id' => $departure->id, 'seats' => 1]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        return $booking->refresh();
    }

    private function enter(Booking $booking): void
    {
        $token = app(Gatekeeper::class)->issue($booking);
        $this->get("/en/portal/enter/{$token}")->assertRedirect('/en/portal');
    }

    public function test_a_link_visitor_chooses_a_password_once_with_the_number_on_file(): void
    {
        $customer = $this->customer();
        $booking = $this->booking($customer);
        $this->enter($booking);

        // The wrong number: a forwarded link alone sets nothing.
        $this->post('/en/portal/password', ['phone' => '7770000', 'password' => 'a-good-password', 'password_confirmation' => 'a-good-password'])
            ->assertSessionHasErrors('phone');
        $this->assertFalse($customer->fresh()->hasPortalPassword());

        $this->post('/en/portal/password', ['phone' => '+960 777-1234', 'password' => 'a-good-password', 'password_confirmation' => 'a-good-password'])
            ->assertRedirect('/en/portal/bookings');

        $stored = (string) DB::table('customers')->where('id', $customer->id)->value('portal_password');
        $this->assertNotSame('a-good-password', $stored, 'Hashed, never in the clear.');
        $this->assertTrue(password_verify('a-good-password', $stored));
        $this->assertArrayNotHasKey('portal_password', $customer->fresh()->toArray());

        // The first one stands: a second link visitor cannot change it.
        $this->post('/en/portal/leave');
        $this->enter($booking);
        $this->post('/en/portal/password', ['phone' => '7771234', 'password' => 'another-password', 'password_confirmation' => 'another-password'])
            ->assertSessionHasErrors('phone');
        $this->assertTrue(password_verify('a-good-password', (string) DB::table('customers')->where('id', $customer->id)->value('portal_password')));
    }

    public function test_signing_in_opens_every_booking_of_the_customer_and_nobody_elses(): void
    {
        $customer = $this->customer();
        $first = $this->booking($customer, 'Ramadan 14 Nights');
        $second = $this->booking($customer, 'Shawwal Umrah');
        app(Gatekeeper::class)->choosePassword($first, '7771234', 'a-good-password');
        $this->post('/en/portal/leave');

        $stranger = $this->customer('7779999');
        $theirs = $this->booking($stranger, 'Somebody Else');

        $this->post('/en/portal/login', ['phone' => '7771234', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('phone');
        $this->get('/en/portal')->assertRedirect('/en/portal/locked');

        $this->post('/en/portal/login', ['phone' => '960 7771234', 'password' => 'a-good-password'])
            ->assertRedirect('/en/portal/bookings');
        $this->assertNotNull($customer->fresh()->portal_last_login_at);

        $this->get('/en/portal/bookings')->assertOk()
            ->assertSee('Ramadan 14 Nights')
            ->assertSee('Shawwal Umrah')
            ->assertDontSee('Somebody Else');

        // Moving between one's own bookings, and only one's own.
        $this->post('/en/portal/bookings', ['booking' => $first->id])->assertRedirect('/en/portal');
        $this->get('/en/portal')->assertOk()->assertSee($first->reference);

        $this->post('/en/portal/bookings', ['booking' => $theirs->id])->assertRedirect('/en/portal/bookings');
        $this->get('/en/portal')->assertOk()->assertSee($first->reference)->assertDontSee($theirs->reference);
    }

    public function test_an_unknown_number_and_a_wrong_password_answer_the_same(): void
    {
        $customer = $this->customer();
        app(Gatekeeper::class)->choosePassword($this->booking($customer), '7771234', 'a-good-password');
        $this->post('/en/portal/leave');

        $unknown = $this->post('/en/portal/login', ['phone' => '7775555', 'password' => 'a-good-password']);
        $wrong = $this->post('/en/portal/login', ['phone' => '7771234', 'password' => 'not-it']);

        $unknown->assertSessionHasErrors('phone');
        $wrong->assertSessionHasErrors('phone');
        $this->assertSame(
            $unknown->getSession()->get('errors')->first('phone'),
            $wrong->getSession()->get('errors')->first('phone'),
            'Nothing in the answer says whether the number is on file.',
        );
    }

    public function test_signing_in_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/en/portal/login', ['phone' => '7771234', 'password' => 'guess-'.$i]);
        }

        $this->post('/en/portal/login', ['phone' => '7771234', 'password' => 'guess-6'])->assertStatus(429);
    }

    public function test_the_office_clears_a_password_and_every_session_it_opened_ends(): void
    {
        $customer = $this->customer();
        $booking = $this->booking($customer);
        app(Gatekeeper::class)->choosePassword($booking, '7771234', 'a-good-password');
        $this->post('/en/portal/leave');
        $this->post('/en/portal/login', ['phone' => '7771234', 'password' => 'a-good-password']);
        $this->get('/en/portal')->assertOk();

        $this->travel(1)->seconds();
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
        Livewire::actingAs($admin)->test(ViewCustomer::class, ['record' => $customer->id])
            ->callAction('clearPortalPassword')
            ->assertNotified('Portal password cleared');
        auth()->logout();

        $this->assertFalse($customer->fresh()->hasPortalPassword());
        $this->get('/en/portal')->assertRedirect('/en/portal/locked');

        // And a new one can be chosen from the next link.
        $this->enter($booking);
        $this->get('/en/portal/bookings')->assertOk()->assertSee('Choose a password');
    }
}
