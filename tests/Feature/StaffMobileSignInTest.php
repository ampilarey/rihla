<?php

namespace Tests\Feature;

use App\Exceptions\DeskRefusal;
use App\Filament\Company\Pages\Auth\Login as CompanyLogin;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\User;
use App\Services\Companies\AddCompany;
use App\Services\Companies\CompanyTeam;
use App\Support\Access;
use App\Support\CompanyRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff sign in with a mobile number as well as an e-mail address, and the
 * busiest lists read as cards on a phone (companies plan, Phase 3i, from
 * Hijaz).
 */
class StaffMobileSignInTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create([
            'email' => 'aishath@rihla.example',
            'phone' => '+960 777-1234',
            'password' => Hash::make('a-staff-password'),
        ])->assignRole(Access::OPERATIONS_MANAGER);
    }

    public function test_a_number_is_kept_by_its_key_so_every_way_of_writing_it_is_one_sign_in(): void
    {
        $this->assertSame('7771234', $this->staff()->phone);
    }

    public function test_staff_sign_in_with_their_mobile_number_or_their_e_mail(): void
    {
        $user = $this->staff();

        foreach (['7771234', '960 777 1234', '+960-777-1234'] as $written) {
            $this->post('/login', ['email' => $written, 'password' => 'a-staff-password']);
            $this->assertAuthenticatedAs($user);
            auth()->logout();
        }

        $this->post('/login', ['email' => 'aishath@rihla.example', 'password' => 'a-staff-password']);
        $this->assertAuthenticatedAs($user);
        auth()->logout();

        $this->post('/login', ['email' => '7771234', 'password' => 'not-it'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => '7779999', 'password' => 'a-staff-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_three_ways_of_writing_one_number_share_one_lockout(): void
    {
        $this->staff();

        foreach (['7771234', '960 777 1234', '+960-777-1234', '777 1234', '7771234'] as $written) {
            $this->post('/login', ['email' => $written, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => '9607771234', 'password' => 'a-staff-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_company_member_signs_in_to_their_panel_with_a_mobile_number(): void
    {
        Notification::fake();
        $coral = app(AddCompany::class)('Coral Umrah', 'Aishath Coral', 'owner@coral.example');
        app(CompanyTeam::class)->add($coral, 'Ahmed Desk', 'ahmed@coral.example', CompanyRole::ROLES[2], null, '7775555');
        $ahmed = User::where('email', 'ahmed@coral.example')->sole();
        $ahmed->forceFill(['password' => Hash::make('a-company-password')])->save();
        $this->assertSame('7775555', $ahmed->phone);

        Filament::setCurrentPanel(Filament::getPanel('company'));
        Livewire::test(CompanyLogin::class)
            ->set('data.email', '+960 777 5555')
            ->set('data.password', 'a-company-password')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($ahmed);

        // A number somebody else signs in with is refused for a new member.
        $this->expectException(DeskRefusal::class);
        app(CompanyTeam::class)->add($coral, 'Someone Else', 'else@coral.example', CompanyRole::ROLES[2], null, '777 5555');
    }

    public function test_the_customers_list_carries_the_number_and_bookings_under_the_name(): void
    {
        $customer = Customer::factory()->create(['name' => 'Aminath Ali', 'phone' => '7771234']);

        Livewire::actingAs(User::factory()->create()->assignRole(Access::SUPER_ADMIN))
            ->test(ListCustomers::class)
            ->assertCanSeeTableRecords([$customer])
            ->assertSee('7771234 · 0 bookings');
    }
}
