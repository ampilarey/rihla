<?php

namespace Tests\Feature;

use App\Exceptions\DeskRefusal;
use App\Filament\Host\Pages\Team;
use App\Models\HostInvitation;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\User;
use App\Services\Hosts\HostTeam;
use App\Support\HostRole;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A host's team and its invitations — §16.6, §16.10, §16 Phase 14.5.
 */
class HostTeamTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::factory()->create(['name' => 'Coral Garden Inn']);
        $this->owner = $this->member(HostRole::OWNER);
    }

    private function member(string $role, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    private function inPanel(?User $user = null): void
    {
        $this->actingAs($user ?? $this->owner);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    // ── Inviting ─────────────────────────────────────────────────────────

    public function test_the_owner_invites_and_the_link_is_stored_only_as_a_hash(): void
    {
        $this->inPanel();

        Livewire::actingAs($this->owner)->test(Team::class)
            ->callAction('invite', data: ['email' => 'Mariyam@Example.test', 'role' => HostRole::RECEPTION])
            ->assertNotified('Send them this link — it is not shown again');

        $invitation = HostInvitation::sole();
        $this->assertSame('mariyam@example.test', $invitation->email);
        $this->assertSame(HostRole::RECEPTION, $invitation->role);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $invitation->token_hash);
    }

    public function test_only_the_owner_manages_the_team(): void
    {
        $manager = $this->member(HostRole::MANAGER);

        $this->actingAs($manager)->get('/host/'.$this->host->slug.'/team')->assertForbidden();
        $this->actingAs($this->owner)->get('/host/'.$this->host->slug.'/team')->assertOk();
    }

    public function test_a_new_invitation_replaces_the_old_one(): void
    {
        $team = app(HostTeam::class);
        $first = $team->invite($this->host, 'mariyam@example.test', HostRole::RECEPTION);
        $second = $team->invite($this->host, 'mariyam@example.test', HostRole::MANAGER);

        $this->assertNull($team->find($first));
        $this->assertSame(HostRole::MANAGER, $team->find($second)?->role);
    }

    // ── Accepting ────────────────────────────────────────────────────────

    public function test_somebody_new_sets_a_password_and_joins(): void
    {
        $token = app(HostTeam::class)->invite($this->host, 'mariyam@example.test', HostRole::RECEPTION, $this->owner);

        $this->get(route('host.join', ['token' => $token]))
            ->assertOk()
            ->assertSee('Join Coral Garden Inn')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->post(route('host.join.register', ['token' => $token]), [
            'name' => 'Mariyam Shifa',
            'password' => 'a-long-and-decent-password',
            'password_confirmation' => 'a-long-and-decent-password',
            'email' => 'attacker@example.test', // ignored: the address is the invitation's
        ])->assertRedirect('/host/'.$this->host->slug);

        $user = User::where('email', 'mariyam@example.test')->sole();
        $this->assertSame(HostRole::RECEPTION, $user->roleAt($this->host));
        $this->assertSame(0, User::where('email', 'attacker@example.test')->count());
        $this->assertAuthenticatedAs($user);

        // Spent.
        $this->assertNull(app(HostTeam::class)->find($token));
        $this->get(route('host.join', ['token' => $token]))->assertNotFound();
    }

    public function test_somebody_signed_in_with_the_right_address_joins_with_a_button(): void
    {
        $existing = User::factory()->create(['email' => 'hassan@example.test']);
        $token = app(HostTeam::class)->invite($this->host, 'hassan@example.test', HostRole::MANAGER);

        $this->actingAs($existing)
            ->post(route('host.join.accept', ['token' => $token]))
            ->assertRedirect('/host/'.$this->host->slug);

        $this->assertSame(HostRole::MANAGER, $existing->roleAt($this->host));
    }

    /** A forwarded link is no use to anybody else. */
    public function test_the_wrong_person_cannot_accept(): void
    {
        $token = app(HostTeam::class)->invite($this->host, 'hassan@example.test', HostRole::MANAGER);
        $stranger = User::factory()->create(['email' => 'stranger@example.test']);

        $this->actingAs($stranger)
            ->post(route('host.join.accept', ['token' => $token]))
            ->assertSessionHasErrors('join');

        $this->assertNull($stranger->roleAt($this->host));
        $this->assertNotNull(app(HostTeam::class)->find($token), 'The invitation is still there for the right person.');
    }

    public function test_an_expired_invitation_opens_nothing(): void
    {
        $token = app(HostTeam::class)->invite($this->host, 'late@example.test', HostRole::RECEPTION);

        $this->travel(HostTeam::INVITATION_DAYS + 1)->days();

        $this->get(route('host.join', ['token' => $token]))->assertNotFound();
    }

    // ── Roles and removal ────────────────────────────────────────────────

    public function test_a_host_always_keeps_an_owner(): void
    {
        $membership = HostMembership::where('user_id', $this->owner->id)->sole();

        try {
            app(HostTeam::class)->changeRole($membership, HostRole::MANAGER);
            $this->fail('The last owner was demoted.');
        } catch (DeskRefusal) {
        }

        $this->expectException(DeskRefusal::class);
        app(HostTeam::class)->remove($membership);
    }

    public function test_the_owner_removes_somebody_and_their_access_ends(): void
    {
        $reception = $this->member(HostRole::RECEPTION);
        $membership = HostMembership::where('user_id', $reception->id)->sole();

        $this->inPanel();
        Livewire::actingAs($this->owner)->test(Team::class)
            ->callAction(TestAction::make('remove')->table($membership));

        $this->assertNull($reception->roleAt($this->host));
        // Their only host: the panel refuses them outright.
        $this->actingAs($reception)->get('/host/'.$this->host->slug)->assertForbidden();
    }

    public function test_an_owner_cannot_touch_another_hosts_team(): void
    {
        $other = Partner::factory()->create();
        $theirs = HostMembership::create(['partner_id' => $other->id, 'user_id' => User::factory()->create()->id, 'role' => HostRole::RECEPTION, 'accepted_at' => now()]);

        $this->inPanel();
        Livewire::actingAs($this->owner)->test(Team::class)
            ->assertCanNotSeeTableRecords([$theirs]);
    }
}
