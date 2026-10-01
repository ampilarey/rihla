<?php

namespace Tests\Feature;

use App\Filament\Host\Pages\RegisterHost;
use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Models\HostMembership;
use App\Models\HostStatement;
use App\Models\Partner;
use App\Models\Property;
use App\Models\Stay;
use App\Models\User;
use App\Support\Access;
use App\Support\EncryptedFile;
use App\Support\HostRole;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The host panel at /host — §16.6, §16 Phase 14.1.
 */
class HostPanelTest extends TestCase
{
    use RefreshDatabase;

    private function hostUser(Partner $host, string $role = HostRole::OWNER): User
    {
        $user = User::factory()->create();

        HostMembership::create([
            'partner_id' => $host->id,
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    private function dashboard(Partner $host): string
    {
        return '/host/'.$host->slug;
    }

    // ── Who gets in ──────────────────────────────────────────────────────

    public function test_a_host_member_opens_their_own_host(): void
    {
        $host = Partner::factory()->create();

        $this->actingAs($this->hostUser($host))->get($this->dashboard($host))
            ->assertOk()
            ->assertSee('You are live');
    }

    public function test_a_host_waiting_for_its_check_is_told_so(): void
    {
        $host = Partner::factory()->unverified()->create();

        $this->actingAs($this->hostUser($host))->get($this->dashboard($host))
            ->assertOk()
            ->assertSee('Your account is being checked');
    }

    /** A host user holds no role and no admin.access: /staff stays shut. */
    public function test_a_host_user_cannot_open_the_staff_panel(): void
    {
        $user = $this->hostUser(Partner::factory()->create());

        $this->assertFalse($user->can('admin.access'));
        $this->assertSame([], $user->getRoleNames()->all());
        $this->actingAs($user)->get('/staff')->assertForbidden();
    }

    /** Staff are not hosts because they are staff. */
    public function test_staff_without_a_membership_cannot_open_the_host_panel(): void
    {
        $staff = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $this->assertFalse($staff->canAccessPanel(Filament::getPanel('host')));
    }

    /** The tenant scope: another host's panel is not there at all. */
    public function test_a_member_of_one_host_cannot_open_another(): void
    {
        $mine = Partner::factory()->create();
        $theirs = Partner::factory()->create();

        $this->actingAs($this->hostUser($mine))->get($this->dashboard($theirs))->assertNotFound();
    }

    public function test_a_suspended_hosts_team_is_shut_out_at_once(): void
    {
        $host = Partner::factory()->create();
        $user = $this->hostUser($host);
        $host->forceFill(['status' => Partner::STATUS_SUSPENDED])->save();

        $this->assertFalse($user->canAccessTenant($host->fresh()));
        $this->assertCount(0, $user->getTenants(Filament::getPanel('host')));
        $this->actingAs($user)->get($this->dashboard($host))->assertNotFound();
    }

    public function test_an_invitation_not_yet_accepted_opens_nothing(): void
    {
        $host = Partner::factory()->create();
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => $host->id, 'user_id' => $user->id, 'role' => HostRole::MANAGER]);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('host')));
    }

    // ── Roles inside a host ──────────────────────────────────────────────

    public function test_reception_runs_the_desk_and_nothing_with_money(): void
    {
        $this->assertTrue(HostRole::allows(HostRole::RECEPTION, HostRole::BOOKINGS));
        $this->assertFalse(HostRole::allows(HostRole::RECEPTION, HostRole::EARNINGS));
        $this->assertFalse(HostRole::allows(HostRole::RECEPTION, HostRole::LISTINGS));
        $this->assertFalse(HostRole::allows(HostRole::RECEPTION, HostRole::REGISTER_UNMASKED));
        $this->assertFalse(HostRole::allows(HostRole::MANAGER, HostRole::TEAM));
        $this->assertTrue(HostRole::allows(HostRole::OWNER, HostRole::TEAM));
        $this->assertFalse(HostRole::allows(null, HostRole::BOOKINGS));
    }

    // ── Signing up ───────────────────────────────────────────────────────

    public function test_sign_up_is_closed_until_the_owner_opens_it(): void
    {
        $this->get('/host/register')->assertNotFound();

        // Switched on, but with no terms to agree to: still closed.
        config(['marketplace.host_registration.enabled' => true]);
        $this->get('/host/register')->assertNotFound();

        // The Breeze sign-up D16 closed stays closed whatever this says.
        $this->get('/register')->assertNotFound();
    }

    private function openRegistration(): void
    {
        config([
            'marketplace.host_registration.enabled' => true,
            'marketplace.host_terms.version' => '2027-01',
            'marketplace.host_terms.url' => 'https://rihla.mv/host-terms',
        ]);
        RateLimiter::clear('host-register:127.0.0.1');
    }

    public function test_a_host_signs_up_and_waits_to_be_checked(): void
    {
        $this->openRegistration();
        Storage::fake('documents');

        Livewire::test(RegisterHost::class)
            ->fillForm([
                'host_name' => 'Fulidhoo Sunrise',
                'kind' => Partner::KIND_GUESTHOUSE,
                'island' => 'Fulidhoo',
                'name' => 'Hassan Ibrahim',
                'phone' => '+960 777 1111',
                'email' => 'hassan@example.test',
                'password' => 'a-long-and-decent-password',
                'passwordConfirmation' => 'a-long-and-decent-password',
                'registration_number' => 'MOT-GH-2024-118',
                'registration_document' => [UploadedFile::fake()->image('registration.jpg')],
                'terms' => true,
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $host = Partner::where('name', 'Fulidhoo Sunrise')->sole();
        $user = User::where('email', 'hassan@example.test')->sole();

        $this->assertSame(Partner::VERIFICATION_PENDING, $host->verification);
        $this->assertSame(Partner::STATUS_PENDING, $host->status);
        $this->assertSame('2027-01', $host->terms_version);
        $this->assertSame(HostRole::OWNER, $user->roleAt($host));
        $this->assertSame([], $user->getRoleNames()->all(), 'A host gets no staff role.');

        // The scan is on the private disk and encrypted at rest.
        $raw = Storage::disk('documents')->get((string) $host->registration_document_path);
        $this->assertTrue(EncryptedFile::looksEncrypted((string) $raw) || ! EncryptedFile::enabled());
    }

    public function test_sign_up_needs_the_registration_when_rihla_requires_it(): void
    {
        $this->openRegistration();

        Livewire::test(RegisterHost::class)
            ->fillForm([
                'host_name' => 'No Papers Inn', 'kind' => Partner::KIND_GUESTHOUSE, 'island' => 'Maafushi',
                'name' => 'X', 'phone' => '1', 'email' => 'x@example.test',
                'password' => 'a-long-and-decent-password', 'passwordConfirmation' => 'a-long-and-decent-password',
                'terms' => true,
            ])
            ->call('register')
            ->assertHasFormErrors(['registration_number' => 'required']);

        $this->assertSame(0, Partner::where('name', 'No Papers Inn')->count());
    }

    // ── The office decides ───────────────────────────────────────────────

    private function staff(): User
    {
        return User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);
    }

    public function test_the_office_verifies_a_waiting_host(): void
    {
        $host = Partner::factory()->unverified()->create(['verification' => Partner::VERIFICATION_PENDING]);
        $staff = $this->staff();

        $this->assertTrue($staff->can('partner.verify'));

        Livewire::actingAs($staff)
            ->test(ListPartners::class)
            ->callAction(TestAction::make('verifyHost')->table($host));

        $host->refresh();
        $this->assertSame(Partner::VERIFIED, $host->verification);
        $this->assertSame(Partner::STATUS_ACTIVE, $host->status);
        $this->assertSame($staff->id, $host->verified_by);
    }

    public function test_a_suspension_needs_a_reason_and_is_undone_by_reinstating(): void
    {
        $host = Partner::factory()->create();

        Livewire::actingAs($this->staff())
            ->test(ListPartners::class)
            ->callAction(TestAction::make('suspendHost')->table($host), data: ['reason' => null])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertSame(Partner::STATUS_ACTIVE, $host->fresh()->status);

        Livewire::actingAs($this->staff())
            ->test(ListPartners::class)
            ->callAction(TestAction::make('suspendHost')->table($host), data: ['reason' => 'Guests reported the rooms did not exist.']);

        $this->assertSame(Partner::STATUS_SUSPENDED, $host->fresh()->status);

        Livewire::actingAs($this->staff())
            ->test(ListPartners::class)
            ->callAction(TestAction::make('reinstateHost')->table($host->fresh()));

        $this->assertSame(Partner::STATUS_ACTIVE, $host->fresh()->status);
    }

    public function test_only_whoever_may_verify_sees_the_host_decisions(): void
    {
        $host = Partner::factory()->create();
        $content = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->assertFalse($content->can('partner.verify'));
    }

    /**
     * Inside /host a Super Admin is whatever their membership says. Before
     * the site audit the global grant answered before the host policies
     * could, so a reception member who was also staff held every host
     * ability at that host.
     */
    public function test_a_super_admin_inside_the_host_panel_is_only_their_membership(): void
    {
        $host = Partner::factory()->create();
        $user = $this->hostUser($host, HostRole::RECEPTION)->assignRole(Access::SUPER_ADMIN);
        $stay = Stay::factory()->create([
            'property_id' => Property::factory()->create(['partner_id' => $host->id])->id,
        ]);

        $this->actingAs($user);
        $this->assertTrue($user->can('view', $stay), 'Outside the panel the global grant stands.');

        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($host);

        $this->assertFalse(HostRole::allows(HostRole::RECEPTION, HostRole::EARNINGS));
        $this->assertFalse($user->can('viewAny', HostStatement::class), 'Reception cannot see the money, Super Admin or not.');
    }
}
