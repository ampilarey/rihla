<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access;
use App\Support\InitialsAvatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Pulse\Contracts\ResolvesUsers;
use Laravel\Pulse\Recorders;
use Tests\TestCase;

/**
 * The Pulse dashboard at /pulse, and the three things about it that are
 * specific to this application rather than to Pulse.
 *
 * See docs/adr/0005-observability-on-a-host-nobody-watches.md.
 */
class PulseDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        $this->get('/pulse')->assertForbidden();
    }

    /**
     * The gate is defined in AuthServiceProvider, *after* Pulse's own
     * local-environment-only default, and this asserts that ordering rather
     * than trusting it. A gate registered where it cannot take effect looks
     * exactly like one that works — until a deploy, when it either locks
     * everyone out or lets everyone in.
     */
    public function test_a_super_admin_can_open_it(): void
    {
        $this->assertFalse($this->app->environment('local'), 'Pulse\'s own default would allow this for free.');

        $this->actingAs($this->superAdmin())->get('/pulse')->assertOk();
    }

    /**
     * Pulse is Livewire, and its layout writes an inline <script> this
     * application does not render and cannot put a nonce on — so /pulse
     * takes the same script-src exception as the staff panel, and the public
     * site keeps its nonce.
     */
    public function test_the_dashboard_takes_the_same_script_exception_as_the_panel(): void
    {
        $dashboard = $this->actingAs($this->superAdmin())->get('/pulse')
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", (string) $dashboard);
        $this->assertStringNotContainsString('nonce-', (string) $dashboard,
            'A nonce alongside unsafe-inline makes the browser ignore unsafe-inline.');

        $public = $this->get('/en')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", (string) $public);
    }

    /**
     * The exception is keyed to the configured path, so moving PULSE_PATH
     * moves it. Left hard-coded, a renamed dashboard would render blank with
     * nothing to say why.
     */
    public function test_the_exception_follows_the_configured_path(): void
    {
        config(['pulse.path' => 'pulse']);

        $onDefault = $this->actingAs($this->superAdmin())->get('/pulse')
            ->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('nonce-', (string) $onDefault);

        // With the config pointed elsewhere, the same URL is an ordinary page
        // again and gets the strict policy.
        config(['pulse.path' => 'somewhere-else']);

        $stillAtPulse = $this->actingAs($this->superAdmin())->get('/pulse')
            ->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", (string) $stillAtPulse);
    }

    /**
     * Pulse shows the SQL of slow queries, the file and line of every
     * exception, and who was signed in. `pulse.view` is in no role's set on
     * purpose; staff access to the admin panel must not carry it.
     */
    public function test_staff_access_alone_is_not_enough(): void
    {
        foreach ([Access::CONTENT_MANAGER, Access::OPERATIONS_MANAGER, Access::REPORTING] as $role) {
            $user = User::factory()->create()->assignRole($role);

            $this->assertTrue($user->can('admin.access'), "{$role} should still reach /admin.");

            $this->actingAs($user)->get('/pulse')->assertForbidden();
        }
    }

    public function test_no_role_is_granted_the_pulse_permission(): void
    {
        foreach (Access::matrix() as $role => $permissions) {
            $this->assertNotContains('pulse.view', $permissions, "{$role} holds pulse.view.");
        }
    }

    /**
     * Laravel 13 refuses to unserialize a class that is not allowlisted, and
     * hands back a __PHP_Incomplete_Class instead. Pulse caches every
     * dashboard query for a few seconds, and its queries return plain rows —
     * so before stdClass was allowlisted, every card threw "the script tried
     * to access a property on an incomplete object".
     *
     * The page still answered 200 throughout, because each card fails inside
     * its own Livewire request. So this asserts the round trip rather than
     * the status code: put a Pulse-shaped value in the cache, read a property
     * back out.
     */
    public function test_a_pulse_shaped_value_survives_the_cache(): void
    {
        Cache::put('pulse-shape', [collect([(object) ['key' => 'GET /', 'count' => 3]]), 1.5, '2026-09-19 00:00:00']);

        [$rows] = Cache::get('pulse-shape');

        $this->assertSame('GET /', $rows->first()->key);
        $this->assertSame(3, $rows->first()->count);
    }

    /**
     * Pulse's default avatar is gravatar.com/avatar/<sha256 of the email>.
     * Opening the dashboard would hand a stable cross-site identifier for
     * each member of staff to a third party — and `img-src` refuses it, so
     * the visible half of that is a broken picture.
     */
    public function test_the_usage_card_does_not_call_a_third_party_for_avatars(): void
    {
        $user = $this->superAdmin();

        $fields = app(ResolvesUsers::class)->load(collect([$user->getKey()]))->find($user->getKey());

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $fields->avatar);
        $this->assertStringNotContainsString('gravatar', $fields->avatar);
    }

    public function test_the_initials_avatar_handles_the_awkward_names(): void
    {
        $this->assertSame('AS', InitialsAvatar::initials('Aminath Shifa'));

        // Leading punctuation is stripped from each word, not the word
        // dropped: "'Aishath" gives A, not an apostrophe.
        $this->assertSame('AN', InitialsAvatar::initials("'Aishath Nasheed"));

        // Only the first two words count, so a middle name is ignored.
        $this->assertSame('MI', InitialsAvatar::initials('Mohamed Ibrahim Didi'));

        $this->assertSame('', InitialsAvatar::initials(null));
        $this->assertSame('', InitialsAvatar::initials('   '));
    }

    /**
     * The Servers card is fed only by `pulse:check`, a daemon this host
     * cannot keep alive (ADR 0002). The card and the recorder have to stay
     * removed together: a card with no recorder is empty forever, and a
     * recorder with no card is work nobody reads.
     */
    public function test_the_servers_card_and_its_recorder_are_both_absent(): void
    {
        $this->assertArrayNotHasKey(
            Recorders\Servers::class,
            config('pulse.recorders'),
            'The Servers recorder is registered but pulse:check cannot run here.',
        );

        // Blade comments stripped first: the view explains at the top which
        // card was removed and why, and naming it there must not satisfy
        // this assertion.
        $markup = preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(resource_path('views/vendor/pulse/dashboard.blade.php')),
        );

        $this->assertStringNotContainsString(
            'pulse.servers',
            (string) $markup,
            'The Servers card is on the dashboard but nothing can fill it.',
        );
    }

    /**
     * On this host the cache driver is the database, and the homepage caches
     * a block on every view. Recording each of those cache reads writes
     * another row to the same database, on every anonymous page view, to
     * report on the cache.
     */
    public function test_cache_interactions_are_not_recorded_by_default(): void
    {
        $this->assertFalse(config('pulse.recorders.'.Recorders\CacheInteractions::class.'.enabled'));
    }
}
