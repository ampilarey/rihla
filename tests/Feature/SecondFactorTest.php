<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireSecondFactor;
use App\Models\User;
use App\Support\Access;
use App\Support\Totp;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

/**
 * The second factor on staff accounts — §10.4's "MFA for staff".
 *
 * ## The property that matters most is that nobody can be locked out
 *
 * A staff account nobody can reach is an outage, and an outage is how a
 * security control gets switched off for good and never switched back on.
 * So enforcement means "you must enrol", never "you cannot get in", and
 * the enrolment and challenge screens are reachable by somebody who has
 * not yet passed the factor — or there is no way to ever get one.
 *
 * ## And the codes are checked against the specification, not against me
 *
 * {@see Totp} implements RFC 6238 directly rather than taking a
 * dependency, so it is pinned to the specification's own published test
 * vectors below. An implementation that only agrees with itself is not
 * compatible with anybody's authenticator.
 */
class SecondFactorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Enforcement is off by default in config, so every test that is about
     * being compelled turns it on explicitly.
     *
     * The default is off because shipping this enforcing would send the
     * owner to enrolment on the next deploy, at whatever moment that
     * landed. That is their decision, not a release's — see config/mfa.php.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['mfa.enforce' => true]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function withFactor(User $user): string
    {
        $secret = Totp::secret();

        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now()])->save();

        return $secret;
    }

    // ── RFC 6238, against its own vectors ────────────────────────────────

    /**
     * Appendix B of RFC 6238, SHA-1.
     *
     * The secret is the ASCII "12345678901234567890" in base32. If these
     * pass, every authenticator app agrees with this implementation; if
     * they do not, nothing else in this file means anything.
     */
    public function test_it_matches_the_published_rfc_6238_vectors(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        foreach ([
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
        ] as $at => $expected) {
            $this->assertSame($expected, Totp::code($secret, $at), "RFC 6238 vector at {$at}.");
        }
    }

    public function test_it_accepts_one_step_of_drift_either_side(): void
    {
        $secret = Totp::secret();
        $now = 1_700_000_000;

        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now), $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - 30), $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now + 30), $now));

        // Two steps is a clock the person should fix, not one this should
        // paper over — a wider window is a longer replay opportunity.
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now - 90), $now));
    }

    public function test_it_refuses_anything_that_is_not_six_digits(): void
    {
        $secret = Totp::secret();

        foreach (['', '12345', '1234567', 'abcdef', '12 34 56'] as $rubbish) {
            $this->assertFalse(Totp::verify($secret, $rubbish), "Accepted [{$rubbish}].");
        }
    }

    /** Somebody reads the key off a screen and types it into a telephone. */
    public function test_the_key_is_shown_in_groups_a_person_can_type(): void
    {
        $this->assertSame('ABCD EFGH', Totp::spaced('ABCDEFGH'));
        $this->assertStringContainsString('otpauth://totp/', Totp::uri('ABCD', 'a@b.test', 'Rihla'));
    }

    // ── Nobody gets locked out ───────────────────────────────────────────

    /**
     * The enrolment screen is reachable by the person who has to enrol.
     *
     * If the middleware guarded it, somebody in a required role with no
     * factor would be redirected to a page that redirects them back.
     */
    public function test_somebody_who_must_enrol_can_reach_the_enrolment_page(): void
    {
        $finance = $this->staff(Access::FINANCE);

        $this->assertTrue($finance->mustHaveSecondFactor());

        $this->actingAs($finance)->get(route('mfa.enrol'))->assertSuccessful();
    }

    /** And the challenge is reachable by somebody who has not passed it. */
    public function test_the_challenge_is_reachable_without_having_passed_it(): void
    {
        $user = $this->staff(Access::FINANCE);
        $this->withFactor($user);

        $this->actingAs($user)->get(route('mfa.challenge'))->assertSuccessful();
    }

    public function test_a_required_role_with_no_factor_is_sent_to_enrol_rather_than_refused(): void
    {
        $this->actingAs($this->staff(Access::FINANCE))
            ->get('/staff')
            ->assertRedirect(route('mfa.enrol'));
    }

    /**
     * The whole round trip, because that is the property that matters.
     *
     * Shut out → enrol → in. Every individual step of this passed while an
     * earlier version left the enrolment page unreachable from behind the
     * middleware, which is a staff account nobody can open. Only walking
     * the whole path catches that.
     */
    public function test_somebody_shut_out_can_walk_all_the_way_back_in(): void
    {
        $finance = $this->staff(Access::FINANCE);

        // 1. Turned away from the panel.
        $this->actingAs($finance)->get('/staff')->assertRedirect(route('mfa.enrol'));

        // 2. The page they were sent to actually opens.
        $this->actingAs($finance)->get(route('mfa.enrol'))->assertSuccessful();

        // 3. And enrolling works from there.
        $secret = (string) session('mfa.pending_secret');

        $this->actingAs($finance)
            ->post(route('mfa.confirm'), ['code' => Totp::code($secret)])
            ->assertSuccessful();

        // 4. Back in, with no further challenge — confirming is proof.
        $this->actingAs($finance)->get('/staff')->assertSuccessful();
    }

    /**
     * A tour leader at a hotel desk on a borrowed telephone must still be
     * able to take a head count.
     */
    public function test_a_role_that_does_not_require_one_is_not_compelled(): void
    {
        $leader = $this->staff(Access::TOUR_LEADER);

        $this->assertFalse($leader->mustHaveSecondFactor());

        $this->actingAs($leader)->get('/staff')->assertSuccessful();
    }

    /**
     * Shipped off, so a deploy cannot change how somebody signs in.
     *
     * Asserted against the config file rather than the test's own override,
     * which is why it reads the default back explicitly.
     */
    public function test_enforcement_is_off_until_somebody_turns_it_on(): void
    {
        $default = require base_path('config/mfa.php');

        $this->assertFalse(
            $default['enforce'],
            'MFA enforcement must ship off: turning it on changes how the owner signs in to a live system, '
            .'and that is their decision rather than a deploy\'s.',
        );
    }

    /** The emergency stop lifts enforcement without unenrolling anybody. */
    public function test_switching_enforcement_off_stops_compelling_but_keeps_asking(): void
    {
        config(['mfa.enforce' => false]);

        $notEnrolled = $this->staff(Access::FINANCE);
        $this->actingAs($notEnrolled)->get('/staff')->assertSuccessful();

        $enrolled = $this->staff(Access::SUPER_ADMIN);
        $this->withFactor($enrolled);

        // Still asked: turning enforcement off must not silently weaken
        // the accounts that already have a factor.
        $this->actingAs($enrolled)->get('/staff')->assertRedirect(route('mfa.challenge'));
    }

    // ── Enrolling ────────────────────────────────────────────────────────

    /**
     * A secret is only live once a code from it has been proved.
     *
     * Otherwise half-finished enrolment locks somebody out with a factor
     * no telephone can mint a code for.
     */
    public function test_an_unconfirmed_secret_is_not_a_second_factor(): void
    {
        $user = $this->staff(Access::FINANCE);

        $user->forceFill(['mfa_secret' => Totp::secret()])->save();

        $this->assertFalse($user->fresh()->hasSecondFactor());
        $this->actingAs($user)->get('/staff')->assertRedirect(route('mfa.enrol'));
    }

    public function test_a_wrong_code_does_not_turn_it_on(): void
    {
        $user = $this->staff(Access::FINANCE);

        $this->actingAs($user)->get(route('mfa.enrol'));

        $this->actingAs($user)
            ->post(route('mfa.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasSecondFactor());
    }

    public function test_the_right_code_turns_it_on_and_hands_over_recovery_codes(): void
    {
        $user = $this->staff(Access::FINANCE);

        $this->actingAs($user)->get(route('mfa.enrol'))->assertSuccessful();

        $secret = (string) session('mfa.pending_secret');

        $response = $this->actingAs($user)
            ->post(route('mfa.confirm'), ['code' => Totp::code($secret)]);

        $response->assertSuccessful()->assertSee('only time these are shown', false);

        $this->assertTrue($user->fresh()->hasSecondFactor());
        $this->assertCount(8, (array) $user->fresh()->mfa_recovery_codes);
    }

    /** They are stored hashed, so a database dump is not eight open doors. */
    public function test_recovery_codes_are_stored_hashed(): void
    {
        $user = $this->staff(Access::FINANCE);

        $plain = $user->regenerateRecoveryCodes();

        $this->assertNotContains($plain[0], (array) $user->fresh()->mfa_recovery_codes);
        $this->assertContains(hash('sha256', $plain[0]), (array) $user->fresh()->mfa_recovery_codes);
    }

    // ── Passing the challenge ────────────────────────────────────────────

    public function test_a_valid_code_lets_the_session_through(): void
    {
        $user = $this->staff(Access::SUPER_ADMIN);
        $secret = $this->withFactor($user);

        $this->actingAs($user)
            ->post(route('mfa.verify'), ['code' => Totp::code($secret)])
            ->assertRedirect();

        $this->actingAs($user)->get('/staff')->assertSuccessful();
    }

    public function test_a_recovery_code_works_once_and_then_does_not(): void
    {
        $user = $this->staff(Access::SUPER_ADMIN);
        $this->withFactor($user);

        $codes = $user->regenerateRecoveryCodes();

        $this->assertTrue($user->fresh()->consumeRecoveryCode($codes[0]));

        // A recovery code that still works after it has been used is a
        // password somebody has written on paper.
        $this->assertFalse($user->fresh()->consumeRecoveryCode($codes[0]));
        $this->assertCount(7, (array) $user->fresh()->mfa_recovery_codes);
    }

    public function test_a_wrong_code_does_not_let_the_session_through(): void
    {
        $user = $this->staff(Access::SUPER_ADMIN);
        $this->withFactor($user);

        $this->actingAs($user)
            ->post(route('mfa.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->actingAs($user)->get('/staff')->assertRedirect(route('mfa.challenge'));
    }

    /**
     * Six digits is a million possibilities — an afternoon of guessing at
     * HTTP speed without a limiter.
     */
    public function test_guessing_is_rate_limited(): void
    {
        $user = $this->staff(Access::SUPER_ADMIN);
        $this->withFactor($user);

        RateLimiter::clear('mfa:'.$user->getKey());

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('mfa.verify'), ['code' => '000000']);
        }

        $this->actingAs($user)
            ->post(route('mfa.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertTrue(RateLimiter::tooManyAttempts('mfa:'.$user->getKey(), 5));
    }

    /** A fixated session must not ride through a factor it never passed. */
    public function test_the_session_id_changes_when_the_factor_is_passed(): void
    {
        $user = $this->staff(Access::SUPER_ADMIN);
        $secret = $this->withFactor($user);

        $this->actingAs($user)->get(route('mfa.challenge'));
        $before = session()->getId();

        $this->actingAs($user)->post(route('mfa.verify'), ['code' => Totp::code($secret)]);

        $this->assertNotSame($before, session()->getId());
    }

    /** The verification expires; a session left open does not outlive it. */
    public function test_a_stale_verification_is_challenged_again(): void
    {
        $user = $this->staff(Access::SUPER_ADMIN);
        $this->withFactor($user);

        $this->actingAs($user)
            ->withSession([
                RequireSecondFactor::VERIFIED_AT => now()->subMinutes(
                    (int) config('mfa.remember_minutes') + 10,
                )->toDateTimeString(),
            ])
            ->get('/staff')
            ->assertRedirect(route('mfa.challenge'));
    }

    // ── Turning it off ───────────────────────────────────────────────────

    /** Refused with a reason, rather than a button that does nothing. */
    public function test_a_required_role_cannot_turn_it_off(): void
    {
        $user = $this->staff(Access::SUPER_ADMIN);
        $secret = $this->withFactor($user);
        $this->passed($user);

        $this->actingAs($user)
            ->post(route('mfa.disable'), ['password' => 'password', 'code' => Totp::code($secret)])
            ->assertSessionHasErrors('code');

        $this->assertTrue($user->fresh()->hasSecondFactor());
    }

    public function test_anybody_else_can_turn_it_off_with_their_password_and_a_code(): void
    {
        $user = $this->staff(Access::TOUR_LEADER);
        $secret = $this->withFactor($user);
        $this->passed($user);

        $this->actingAs($user)
            ->post(route('mfa.disable'), ['password' => 'password', 'code' => Totp::code($secret)])
            ->assertRedirect(route('mfa.settings'));

        $this->assertFalse($user->fresh()->hasSecondFactor());
    }

    /**
     * The password alone is the one thing the second step exists to not
     * trust. Before the site audit anybody holding it could switch the
     * step off from a plain sign-in and then open the panel unchallenged.
     */
    public function test_turning_it_off_needs_a_live_code_not_only_the_password(): void
    {
        $user = $this->staff(Access::TOUR_LEADER);
        $this->withFactor($user);
        $this->passed($user);

        $this->actingAs($user)
            ->post(route('mfa.disable'), ['password' => 'password'])
            ->assertSessionHasErrors('code');

        $this->actingAs($user)
            ->post(route('mfa.disable'), ['password' => 'password', 'code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertTrue($user->fresh()->hasSecondFactor());
    }

    /** And the settings page itself sits behind the step: a session that has not passed it is challenged. */
    public function test_the_settings_and_the_off_switch_are_behind_the_step(): void
    {
        $user = $this->staff(Access::TOUR_LEADER);
        $secret = $this->withFactor($user);

        $this->actingAs($user)->get(route('mfa.settings'))->assertRedirect(route('mfa.challenge'));
        $this->actingAs($user)
            ->post(route('mfa.disable'), ['password' => 'password', 'code' => Totp::code($secret)])
            ->assertRedirect(route('mfa.challenge'));

        $this->assertTrue($user->fresh()->hasSecondFactor());
    }

    /**
     * A time-based code is accepted once. The app shows the same digits
     * for thirty seconds and the check allows a step either side, so a
     * code read over a shoulder was good for ninety seconds more.
     */
    public function test_a_code_is_accepted_once(): void
    {
        $user = $this->staff(Access::TOUR_LEADER);
        $secret = $this->withFactor($user);
        $code = Totp::code($secret);

        $this->actingAs($user)->post(route('mfa.verify'), ['code' => $code])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('mfa.verify'), ['code' => $code])->assertSessionHasErrors('code');
    }

    /**
     * The step covers every signed-in screen, not only the two panels: the
     * leader portal, the old admin screens, profile, devices, Pulse. Before
     * the site audit a Super Admin's password alone opened Pulse.
     */
    public function test_every_signed_in_screen_is_behind_the_step(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['leader.index', 'admin.audit.index', 'profile.edit', 'devices.index', 'mfa.settings', 'mfa.disable', 'staff.invoice'] as $name) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route, "{$name} is not routed.");
            $this->assertContains('mfa', $route->middleware(), "{$name} is not behind the second step.");
        }

        foreach (['mfa.challenge', 'mfa.verify', 'mfa.enrol', 'mfa.confirm'] as $name) {
            $this->assertContains('mfa', $routes->getByName($name)?->middleware() ?? [], "{$name} must be inside the group so the exemption is tested.");
        }

        $this->assertContains(RequireSecondFactor::class, config('pulse.middleware'));

        $user = $this->staff(Access::SUPER_ADMIN);
        $this->withFactor($user);
        $this->actingAs($user)->get('/profile')->assertRedirect(route('mfa.challenge'));
        $this->actingAs($user)->get('/en/leader')->assertRedirect(route('mfa.challenge'));
    }

    /**
     * Livewire re-runs only the panel's *persistent* middleware on an
     * update request — every button in the panel. Not persistent, and an
     * action ran for a session that never passed the challenge.
     */
    public function test_the_step_is_persistent_in_both_panels(): void
    {
        foreach (['staff', 'host'] as $panel) {
            $this->assertContains(RequireSecondFactor::class, Filament::getPanel($panel)->getAuthMiddleware(), "{$panel}: not wired.");
            Filament::getPanel($panel)->boot();
        }

        // The panel hands its persistent list to Livewire when it boots.
        $this->assertContains(RequireSecondFactor::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
    }

    /** A session that has passed the step, for tests of what lies behind it. */
    private function passed(User $user): void
    {
        $this->withSession([RequireSecondFactor::VERIFIED_AT => now()->toDateTimeString()]);
    }

    public function test_turning_it_off_needs_the_password(): void
    {
        $user = $this->staff(Access::TOUR_LEADER);
        $secret = $this->withFactor($user);
        $this->passed($user);

        $this->actingAs($user)
            ->post(route('mfa.disable'), ['password' => 'not-the-password', 'code' => Totp::code($secret)])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->hasSecondFactor());
    }

    // ── What is in the database ──────────────────────────────────────────

    /**
     * A shared-host database dump is the realistic threat (ADR 0002), and a
     * TOTP secret in a dump is a permanent second factor for whoever reads it.
     */
    public function test_the_secret_is_ciphertext_in_the_column(): void
    {
        $user = $this->staff(Access::SUPER_ADMIN);
        $secret = $this->withFactor($user);

        $raw = (string) DB::table('users')
            ->where('id', $user->getKey())
            ->value('mfa_secret');

        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, $user->fresh()->mfa_secret);
    }
}
