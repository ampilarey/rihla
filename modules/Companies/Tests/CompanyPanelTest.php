<?php

namespace Modules\Companies\Tests;

use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\CompanyRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Companies\Services\AddCompany;
use Tests\TestCase;

/**
 * Phase 2a of docs/UMRAH_COMPANIES_PLAN.md: each Umrah company's own panel,
 * who gets in, and what a role there may do.
 */
class CompanyPanelTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'Coral Umrah'): Partner
    {
        Notification::fake();

        return app(AddCompany::class)($name, 'Aishath Coral', strtolower(str_replace(' ', '', $name)).'@example.com');
    }

    private function member(Partner $company, string $role): User
    {
        $user = User::factory()->create();
        DB::table('company_memberships')->insert([
            'company_id' => $company->id, 'user_id' => $user->id, 'role' => $role,
            'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_rihlas_admin_adds_a_company_and_its_owner_is_asked_to_choose_a_password(): void
    {
        Notification::fake();
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        Livewire::actingAs($admin)
            ->test(ListPartners::class)
            ->callAction('addCompany', [
                'name' => 'Coral Umrah', 'owner_name' => 'Aishath Coral', 'owner_email' => 'Aishath@Coral.example',
                // The licence checked is required (Phase 5b).
                'licence_number' => 'MIA-HU-0420', 'licence_expires_on' => now()->addYear()->toDateString(), 'licence_register' => 'Ministry of Islamic Affairs',
            ])
            ->assertHasNoActionErrors();

        $company = Partner::where('slug', 'coral-umrah')->sole();
        $this->assertTrue($company->is_umrah_company);
        $this->assertSame(Partner::VERIFIED, $company->verification);
        $this->assertSame($admin->id, $company->verified_by, 'Whoever checked the licence is recorded.');

        $owner = User::where('email', 'aishath@coral.example')->sole();
        $this->assertSame(CompanyRole::OWNER, $owner->roleAtCompany($company));
        Notification::assertSentTo($owner, ResetPassword::class);
        $this->assertFalse($owner->hasAnyRole(Access::ROLES), 'A company owner is not Rihla staff.');
    }

    public function test_only_staff_who_may_make_partners_and_users_see_the_action(): void
    {
        Livewire::actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))
            ->test(ListPartners::class)
            ->assertActionHidden('addCompany');
    }

    public function test_a_member_reaches_their_company_and_nobody_elses(): void
    {
        $coral = $this->company();
        $pearl = $this->company('Pearl Umrah');
        $owner = User::where('email', 'coralumrah@example.com')->sole();

        $this->actingAs($owner)->get('/company/'.$coral->slug)->assertOk()->assertSee('Coral Umrah')->assertSee('signed in as Owner');
        $this->actingAs($owner)->get('/company/'.$pearl->slug)->assertNotFound();
    }

    public function test_company_staff_cannot_open_rihlas_panel_and_rihlas_staff_cannot_open_a_companys(): void
    {
        $coral = $this->company();
        $owner = User::where('email', 'coralumrah@example.com')->sole();
        $rihla = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $this->actingAs($owner)->get('/staff')->assertForbidden();
        $this->actingAs($rihla)->get('/company/'.$coral->slug)->assertForbidden();
    }

    public function test_the_panel_gets_the_script_policy_filament_needs(): void
    {
        // Filament runs inline and evaluated scripts, as on /staff and /host.
        // A panel left on the public site's policy loads, answers 200 and
        // has every button dead in a real browser; no other test sees it.
        $policy = (string) $this->get('/company/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'unsafe-eval'", $policy);
    }

    public function test_a_guesthouse_is_not_a_company_even_with_a_membership_row(): void
    {
        $guesthouse = Partner::factory()->create(['kind' => Partner::KIND_AGENCY]);
        $user = $this->member($guesthouse, CompanyRole::OWNER);

        $this->actingAs($user)->get('/company/'.$guesthouse->slug)->assertForbidden();
    }

    public function test_the_panel_works_for_its_company_only(): void
    {
        $coral = $this->company();
        $owner = User::where('email', 'coralumrah@example.com')->sole();

        Booking::factory()->create(); // Rihla's.

        $this->actingAs($owner)->get('/company/'.$coral->slug)->assertOk();

        // The request ran for Coral: Rihla's booking was invisible to it.
        $this->assertSame($coral->id, app(CompanyContext::class)->scopeId());
        $this->assertSame(0, Booking::count());
    }

    public function test_a_role_holds_rihlas_permissions_for_its_job_and_nothing_of_rihlas_own(): void
    {
        $owner = CompanyRole::permissions(CompanyRole::OWNER);
        $this->assertContains('booking.update', $owner);
        $this->assertContains('payment.reconcile', $owner);

        foreach (['user.create', 'setting.update', 'partner.verify', 'stay.confirm', 'article.update', 'audit.viewAny', 'pulse.view', 'heroBanner.update'] as $rihlas) {
            $this->assertNotContains($rihlas, $owner, "An Owner holds Rihla's [{$rihlas}].");
        }

        // A company's Finance is Rihla's Finance, narrowed.
        $finance = CompanyRole::permissions(Access::FINANCE);
        $this->assertContains('payment.reconcile', $finance);
        $this->assertNotContains('booking.discount', CompanyRole::permissions(Access::TOUR_LEADER));
        $this->assertSame([], CompanyRole::permissions('Super Admin'), 'Rihla-only roles mean nothing at a company.');
        $this->assertSame([], CompanyRole::permissions(null));
    }

    public function test_inside_the_panel_a_permission_is_the_role_at_that_company(): void
    {
        $coral = $this->company();
        $finance = $this->member($coral, Access::FINANCE);
        $leader = $this->member($coral, Access::TOUR_LEADER);

        $can = function (User $user, string $permission) use ($coral): bool {
            $this->actingAs($user)->get('/company/'.$coral->slug)->assertOk();

            return $user->can($permission);
        };

        $this->assertTrue($can($finance, 'payment.reconcile'));
        $this->assertFalse($can($leader, 'payment.reconcile'));
        $this->assertFalse($can($finance, 'user.create'), "Rihla's permissions are no company role's.");
    }
}
