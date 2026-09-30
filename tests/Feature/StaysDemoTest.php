<?php

namespace Tests\Feature;

use App\Console\Commands\StaysDemo;
use App\Models\HostStatement;
use App\Models\Partner;
use App\Models\Review;
use App\Models\Stay;
use App\Models\User;
use App\Support\HostRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `stays:demo` — a demo host to click through on the test server.
 */
class StaysDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_host_with_every_screen_populated_and_prints_a_working_login(): void
    {
        $this->artisan('stays:demo')
            ->expectsOutputToContain('Email:    '.StaysDemo::EMAIL)
            ->expectsOutputToContain('Password: ')
            ->assertSuccessful();

        $host = Partner::where('slug', StaysDemo::SLUG)->sole();
        $user = User::where('email', StaysDemo::EMAIL)->sole();

        $this->assertSame(HostRole::OWNER, $user->roleAt($host));
        $this->assertTrue($host->isListed());
        $this->assertEqualsCanonicalizing(
            [Stay::COMPLETED, Stay::CHECKED_IN, Stay::CONFIRMED, Stay::REQUESTED],
            Stay::whereHas('property', fn ($q) => $q->where('partner_id', $host->id))->pluck('status')->all(),
        );
        $this->assertSame(1, Review::where('partner_id', $host->id)->visible()->count());
        $this->assertGreaterThan(0, HostStatement::where('partner_id', $host->id)->count());
        $this->assertSame(1, $host->packages()->count());

        // Every host screen opens for the demo owner.
        foreach (['', '/bookings', '/listings', '/packages', '/calendar', '/housekeeping', '/messages', '/reviews', '/earnings', '/reports', '/my-page', '/team', '/payout-details'] as $path) {
            $this->actingAs($user)->get('/host/'.$host->slug.$path)->assertOk();
        }
    }

    public function test_running_it_again_keeps_the_data_and_issues_a_new_password(): void
    {
        $this->artisan('stays:demo')->assertSuccessful();
        $first = User::where('email', StaysDemo::EMAIL)->sole()->password;

        $this->artisan('stays:demo')->assertSuccessful();

        $this->assertSame(1, Partner::where('slug', StaysDemo::SLUG)->count());
        $this->assertSame(4, Stay::count());
        $this->assertNotSame($first, User::where('email', StaysDemo::EMAIL)->sole()->password);
        $this->assertFalse(Hash::check('password', User::where('email', StaysDemo::EMAIL)->sole()->password));
    }

    public function test_it_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('stays:demo')->assertFailed();

        $this->assertSame(0, Partner::where('slug', StaysDemo::SLUG)->count());
        $this->assertSame(0, User::where('email', StaysDemo::EMAIL)->count());
    }
}
