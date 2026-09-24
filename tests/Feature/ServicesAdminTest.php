<?php

namespace Tests\Feature;

use App\Filament\Pages\Services as ServicesPage;
use App\Models\User;
use App\Support\Access;
use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin screen for the service registry — §15.3 (Phase 8.1).
 *
 * This is where the owner's own ask lives: "admin to on/off some services".
 * `ServicesTest` proves the registry is correct; this proves the screen the
 * owner actually uses is wired to it.
 */
class ServicesAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    public function test_a_super_admin_can_reach_the_services_page(): void
    {
        $this->actingAs($this->superAdmin())->get('/staff/services')->assertOk();
    }

    public function test_a_role_without_setting_update_is_refused(): void
    {
        // Tour Leader holds no setting.* permission at all — see Access::matrix().
        $leader = User::factory()->create()->assignRole(Access::TOUR_LEADER);

        $this->actingAs($leader)->get('/staff/services')->assertForbidden();
    }

    public function test_the_form_loads_pre_filled_with_current_state(): void
    {
        Services::save(['stays_guesthouses' => Services::COMING_SOON]);

        Livewire::actingAs($this->superAdmin())
            ->test(ServicesPage::class)
            ->assertFormSet([
                'stays_guesthouses' => Services::COMING_SOON,
                'stays_island_holidays' => Services::OFF,
                'stays_rooms' => Services::OFF,
            ]);
    }

    public function test_saving_the_form_changes_the_registry(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(ServicesPage::class)
            ->fillForm([
                'stays_guesthouses' => Services::ON,
                'stays_island_holidays' => Services::COMING_SOON,
                'stays_rooms' => Services::OFF,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Services::isOn('stays_guesthouses'));
        $this->assertTrue(Services::isComingSoon('stays_island_holidays'));
        $this->assertTrue(Services::isOff('stays_rooms'));
    }
}
