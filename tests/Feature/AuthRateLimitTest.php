<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Registration and password reset both send mail to whatever address the
 * request names, and neither was rate limited. An unauthenticated caller
 * could use either to deliver mail to an arbitrary inbox as fast as the
 * server would answer.
 *
 * Registration only began sending mail when User took on the MustVerifyEmail
 * contract, so this is a gap that widened rather than one that was always
 * there. Login was already throttled by LoginRequest; these were not.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_limited(): void
    {
        // Closed by default (D16); the limit matters for the day it opens.
        config(['security.registration_open' => true]);
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('register'), [
                'name' => "Person {$i}",
                'email' => "person{$i}@example.com",
                'password' => 'password-one',
                'password_confirmation' => 'password-one',
            ])->assertSessionHasNoErrors();

            $this->post(route('logout'));
        }

        $this->post(route('register'), [
            'name' => 'One too many',
            'email' => 'flood@example.com',
            'password' => 'password-one',
            'password_confirmation' => 'password-one',
        ])->assertTooManyRequests();

        $this->assertDatabaseMissing('users', ['email' => 'flood@example.com']);
    }

    /**
     * Limited by caller, so one source cannot flood many addresses.
     */
    public function test_password_reset_is_limited_per_caller(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            User::factory()->create(['email' => "target{$i}@example.com"]);

            $this->post(route('password.email'), ['email' => "target{$i}@example.com"])
                ->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => 'target1@example.com'])
            ->assertTooManyRequests();
    }

    /**
     * And limited by address, so many sources cannot flood one inbox.
     *
     * Laravel's password broker already refuses a second link for the same
     * address within 60 seconds, but that is a per-request delay, not a cap:
     * it still permits sixty messages an hour to one inbox. This limiter caps
     * it at three. The broker's refusals are session errors rather than 429s
     * and still consume the allowance, because the throttle middleware runs
     * before the controller.
     */
    public function test_password_reset_is_limited_per_address(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'victim@example.com']);

        foreach (range(1, 3) as $i) {
            $this->post(route('password.email'), ['email' => 'victim@example.com'])
                ->assertStatus(302);
        }

        // Matched case-insensitively, so varying the capitalisation does not
        // buy another three.
        $this->post(route('password.email'), ['email' => 'VICTIM@example.com'])
            ->assertTooManyRequests();
    }

    /**
     * A reset token is guessable without a limit on attempts.
     */
    public function test_reset_submission_is_limited(): void
    {
        User::factory()->create(['email' => 'target@example.com']);

        foreach (range(1, 6) as $i) {
            $this->post(route('password.store'), [
                'token' => "guess-{$i}",
                'email' => 'target@example.com',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ]);
        }

        $this->post(route('password.store'), [
            'token' => 'guess-7',
            'email' => 'target@example.com',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertTooManyRequests();
    }

    public function test_password_confirmation_is_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->post('/confirm-password', ['password' => "wrong-{$i}"]);
        }

        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'wrong-again'])
            ->assertTooManyRequests();
    }

    /**
     * Login's limit predates this change and is enforced in LoginRequest
     * rather than by middleware; asserted here so the whole surface is
     * covered in one place.
     */
    public function test_login_is_still_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->post(route('login'), [
                'email' => $user->email,
                'password' => "wrong-{$i}",
            ]);
        }

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-again',
        ])->assertSessionHasErrors('email');
    }
}
