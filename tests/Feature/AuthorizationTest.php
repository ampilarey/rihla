<?php

namespace Tests\Feature;

use App\Filament\Resources\HeroBanners\HeroBannerResource;
use App\Filament\Resources\WhySections\WhySectionResource;
use App\Models\GuideStep;
use App\Models\Trip;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Authorisation used to be a single `is_admin` boolean: you were an admin or
 * you were nothing. That cannot express the nine staff functions Rihla has,
 * and it cannot express customer-side access at all, which depends on a
 * person's relationship to a booking rather than on any role.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function trip(): Trip
    {
        return Trip::create([
            'title' => 'Seven Nights in Madinah',
            'slug' => 'seven-nights-madinah',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-08',
            'status' => 'upcoming',
            'is_published' => true,
        ]);
    }

    public function test_every_role_and_permission_is_seeded_by_migration(): void
    {
        foreach (Access::ROLES as $role) {
            $this->assertNotNull(
                Role::where('name', $role)->first(),
                "Role {$role} is missing.",
            );
        }

        foreach (Access::PERMISSIONS as $permission) {
            $this->assertNotNull(
                Permission::where('name', $permission)->first(),
                "Permission {$permission} is missing.",
            );
        }
    }

    /**
     * Super Admin is granted everything through Gate::before rather than by
     * holding every permission, so a permission added later cannot silently
     * lock out the one role that must never be locked out.
     */
    public function test_super_admin_holds_no_permissions_but_may_do_everything(): void
    {
        $user = $this->staff(Access::SUPER_ADMIN);

        $this->assertCount(0, $user->getAllPermissions());

        foreach (Access::PERMISSIONS as $permission) {
            $this->assertTrue($user->can($permission), "Super Admin denied {$permission}.");
        }

        // Including an ability that does not exist yet.
        $this->assertTrue($user->can('booking.refund.approve'));
    }

    public function test_a_user_with_no_role_cannot_reach_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login_not_forbidden(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    /**
     * The flag is superseded. Leaving it able to authorise would mean two
     * sources of truth, which is how the wrong person keeps access after
     * their role is removed.
     */
    public function test_the_is_admin_flag_no_longer_grants_anything(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->assertFalse($user->can('admin.access'));

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_content_manager_may_edit_trips(): void
    {
        $trip = $this->trip();

        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(route('admin.trips.edit', $trip))
            ->assertOk();
    }

    /**
     * Social links and contact details are an operations decision, not a
     * content one.
     */
    public function test_content_manager_may_not_touch_settings(): void
    {
        $manager = $this->staff(Access::CONTENT_MANAGER);

        $this->actingAs($manager)->get(route('admin.settings.index'))->assertForbidden();
        $this->actingAs($manager)->post(route('admin.settings.update'), [])->assertForbidden();
    }

    public function test_operations_manager_may_touch_settings(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(route('admin.settings.index'))
            ->assertOk();
    }

    public function test_reporting_may_look_but_not_change(): void
    {
        $trip = $this->trip();
        $reporter = $this->staff(Access::REPORTING);

        $this->actingAs($reporter)->get(route('admin.trips.index'))->assertOk();
        $this->actingAs($reporter)->get(route('admin.trips.show', $trip))->assertOk();

        $this->actingAs($reporter)->get(route('admin.trips.create'))->assertForbidden();
        $this->actingAs($reporter)->delete(route('admin.trips.destroy', $trip))->assertForbidden();

        $this->assertDatabaseHas('trips', ['id' => $trip->id]);
    }

    public function test_a_tour_leader_may_read_trips_but_not_edit_them(): void
    {
        $trip = $this->trip();
        $leader = $this->staff(Access::TOUR_LEADER);

        $this->actingAs($leader)->get(route('admin.trips.index'))->assertOk();
        $this->actingAs($leader)->get(route('admin.trips.edit', $trip))->assertForbidden();
        $this->actingAs($leader)->get(route('admin.media.index'))->assertForbidden();
    }

    /**
     * Reordering and publishing are changes to the records but reach the
     * controller by routes the resource mapping does not cover, so they are
     * the ones most likely to be left open.
     */
    public function test_the_non_resource_admin_actions_are_guarded(): void
    {
        $step = GuideStep::factory()->create();
        $reporter = $this->staff(Access::REPORTING);

        $this->actingAs($reporter)
            ->post(route('admin.guide-steps.toggle-status', $step))
            ->assertForbidden();

        $this->actingAs($reporter)
            ->post(route('admin.guide-steps.update-order'), ['order' => []])
            ->assertForbidden();

        $this->actingAs($reporter)
            ->post(route('admin.guide-steps.bulk-update-status'), ['ids' => [$step->id]])
            ->assertForbidden();
    }

    /**
     * Every admin route must be covered. A route that no policy guards is
     * reachable by any role that can open the panel at all, which includes
     * the roles seeded with nothing but admin.access.
     */
    /**
     * Booking Staff used to hold `admin.access` and nothing else, which is
     * what this test was named for. It now holds `booking.*`, `customer.*`
     * and read-only access to the product — and still none of the content
     * routes below, which is the point. Visa Staff is checked alongside it
     * as the role that genuinely has only the panel.
     */
    public function test_no_content_route_is_reachable_by_a_role_without_content_permissions(): void
    {
        $trip = $this->trip();
        $step = GuideStep::factory()->create();

        foreach ([Access::BOOKING_STAFF, Access::VISA_STAFF] as $role) {
            $this->assertNoContentRouteIsReachable($this->staff($role), $trip, $step);
        }
    }

    private function assertNoContentRouteIsReachable(User $user, Trip $trip, GuideStep $step): void
    {
        foreach ([
            ['get', route('admin.trips.index')],
            ['get', route('admin.trips.create')],
            ['get', route('admin.trips.edit', $trip)],
            ['get', route('admin.media.index')],
            ['get', route('admin.guide-steps.index')],
            ['get', route('admin.guide-steps.edit', $step)],
            // Moved to the staff panel (§9.2). The same people must still
            // be kept out — which is the property this line has always held.
            ['get', HeroBannerResource::getUrl('index')],
            ['get', route('admin.settings.index')],
            // Moved to the staff panel (§9.2); the same people must still be kept out.
            ['get', WhySectionResource::getUrl('index')],
        ] as [$method, $url]) {
            $this->actingAs($user)
                ->{$method}($url)
                ->assertForbidden(sprintf(
                    '%s was reachable by %s, which holds no content permissions.',
                    $url,
                    $user->getRoleNames()->implode(', '),
                ));
        }
    }

    public function test_the_dashboard_is_reachable_by_any_staff_role(): void
    {
        foreach (Access::ROLES as $role) {
            $this->actingAs($this->staff($role))
                ->get('/dashboard')
                ->assertRedirect(route('admin.dashboard'));
        }
    }
}
