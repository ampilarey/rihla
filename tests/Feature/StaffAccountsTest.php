<?php

namespace Tests\Feature;

use App\Exceptions\LastSuperAdmin;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff accounts and the roles they hold — the first Filament module.
 *
 * A gap rather than a duplicate: nine staff roles were introduced in Phase 1
 * with no screen to assign them. An account could only be made with
 * `php artisan admin:create`, and a role could only be granted from tinker —
 * so in practice everyone was a Super Admin, which is the opposite of what
 * the roles are for.
 */
class StaffAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    /** The role checkboxes are keyed by id, because the options come from the relationship. */
    private function roleId(string $name): int
    {
        return Role::findByName($name, 'web')->getKey();
    }

    public function test_a_super_admin_can_list_staff(): void
    {
        $admin = $this->superAdmin();
        $editor = User::factory()->create(['name' => 'Aminath'])->assignRole(Access::CONTENT_MANAGER);

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertCanSeeTableRecords([$admin, $editor])
            ->assertSee('Aminath');
    }

    /**
     * Only Super Admin holds `user.*`. A role that can grant roles can grant
     * its own, so this is not a default anyone inherits.
     */
    public function test_another_staff_role_cannot_reach_the_screen(): void
    {
        $editor = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->actingAs($editor)->get('/staff/users')->assertForbidden();
    }

    public function test_the_screen_does_not_appear_in_the_navigation_for_other_roles(): void
    {
        $editor = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->actingAs($editor)->get('/staff')->assertOk()->assertDontSee('Staff account');
    }

    public function test_a_staff_account_can_be_created_with_a_role(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Hassan',
                'email' => 'hassan@rihla.mv',
                'password' => 'a-long-enough-password',
                'roles' => [$this->roleId(Access::BOOKING_STAFF)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'hassan@rihla.mv')->sole();

        $this->assertTrue($created->hasRole(Access::BOOKING_STAFF));
        $this->assertTrue(Hash::check('a-long-enough-password', $created->password));
    }

    /** The whole point of the screen: granting and revoking without tinker. */
    public function test_roles_can_be_changed(): void
    {
        $editor = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        Livewire::actingAs($this->superAdmin())
            ->test(EditUser::class, ['record' => $editor->getKey()])
            ->fillForm(['roles' => [$this->roleId(Access::REPORTING)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $editor->refresh();

        $this->assertTrue($editor->hasRole(Access::REPORTING));
        $this->assertFalse($editor->hasRole(Access::CONTENT_MANAGER));
    }

    /**
     * A blank password field means "leave it alone", not "set the password to
     * the empty string" — which would lock the account out silently.
     */
    public function test_saving_without_a_password_keeps_the_old_one(): void
    {
        $editor = User::factory()->create(['password' => Hash::make('the-original-password')])
            ->assignRole(Access::CONTENT_MANAGER);

        Livewire::actingAs($this->superAdmin())
            ->test(EditUser::class, ['record' => $editor->getKey()])
            ->fillForm(['name' => 'Renamed', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $editor->refresh();

        $this->assertSame('Renamed', $editor->name);
        $this->assertTrue(Hash::check('the-original-password', $editor->password));
    }

    /** Two accounts cannot share an email; the form says so rather than throwing. */
    public function test_a_duplicate_email_is_refused(): void
    {
        $existing = User::factory()->create(['email' => 'taken@rihla.mv']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Someone',
                'email' => $existing->email,
                'password' => 'a-long-enough-password',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    /**
     * Deleting your own account from the staff screen signs you out of the
     * panel you are standing in. The action hides itself rather than relying
     * on a policy: `Gate::before` grants Super Admin every ability before a
     * policy method runs, so a rule written there would never fire for the
     * only people who can reach this screen.
     */
    public function test_the_delete_action_is_hidden_on_your_own_row(): void
    {
        $admin = $this->superAdmin();
        $other = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertTableActionHidden('delete', $admin)
            ->assertTableActionVisible('delete', $other);
    }

    /**
     * The invariant, enforced on the model rather than in a policy for the
     * same reason — and so it holds for the profile page's "delete my
     * account" and a careless tinker session too.
     */
    public function test_the_last_super_admin_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $other = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        // Two of them: either may go.
        $other->delete();
        $this->assertSame(1, User::role(Access::SUPER_ADMIN)->count());

        $this->expectException(LastSuperAdmin::class);

        $admin->delete();
    }

    public function test_the_last_super_admin_survives_the_delete_my_account_page(): void
    {
        $admin = $this->superAdmin();

        try {
            $admin->delete();
        } catch (LastSuperAdmin) {
            // Expected.
        }

        $this->assertModelExists($admin);
    }

    /**
     * `user.*` must reach nobody but Super Admin.
     *
     * The content permission set used to be defined by exclusion —
     * "everything except settings and the audit log" — so adding `user.*` to
     * the permission list silently gave the Content Manager and the
     * Operations Manager the ability to create staff accounts and hand out
     * roles, including their own. Found by opening the screen as the wrong
     * role. A list that grows by default grants by default.
     */
    public function test_no_other_role_can_manage_staff(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $user = User::factory()->create()->assignRole($role);

            foreach (['viewAny', 'create', 'update', 'delete'] as $action) {
                $this->assertFalse($user->can("user.{$action}"),
                    "[{$role}] holds user.{$action}, which only Super Admin may have.");
            }
        }
    }

    /** `is_admin` is deprecated and must not be settable from a form. */
    public function test_the_form_cannot_set_the_deprecated_admin_flag(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Hassan',
                'email' => 'hassan@rihla.mv',
                'password' => 'a-long-enough-password',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse((bool) User::where('email', 'hassan@rihla.mv')->sole()->is_admin);
    }
}
