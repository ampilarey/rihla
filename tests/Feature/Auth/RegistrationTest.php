<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registration is closed by default (D16) and works when opened.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_closed_by_default(): void
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_registration_screen_can_be_rendered_when_open(): void
    {
        config(['security.registration_open' => true]);

        $this->get('/register')->assertStatus(200);
    }

    public function test_new_users_can_register_when_open(): void
    {
        config(['security.registration_open' => true]);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
