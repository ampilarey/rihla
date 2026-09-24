<?php

namespace Tests\Feature;

use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The service registry's gate — §15.3 (Phase 8.1) of the upgrade plan.
 *
 * No route uses this middleware yet: Phase 9 attaches it to the first real
 * Stays page. Registering a throwaway route inside the test is the honest
 * way to prove the middleware's own behaviour without waiting for that
 * route to exist — the same middleware, unchanged, is what Phase 9 attaches
 * to a real controller, and its own tests exercise it there again through
 * the genuine route.
 */
class EnsureServiceEnabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/__test/gated-service', function () {
            return response(request()->attributes->get('service_state', 'no state was shared'));
        })->middleware('service:stays_guesthouses');
    }

    public function test_an_off_service_404s(): void
    {
        // stays_guesthouses defaults to off; nothing has switched it on.
        $this->get('/__test/gated-service')->assertNotFound();
    }

    public function test_a_coming_soon_service_is_reachable_and_says_so(): void
    {
        Services::save(['stays_guesthouses' => Services::COMING_SOON]);

        $this->get('/__test/gated-service')
            ->assertOk()
            ->assertSee(Services::COMING_SOON);
    }

    public function test_an_on_service_is_reachable_and_says_so(): void
    {
        Services::save(['stays_guesthouses' => Services::ON]);

        $this->get('/__test/gated-service')
            ->assertOk()
            ->assertSee(Services::ON);
    }

    /**
     * A route middleware'd for a key that is not in the catalogue at all —
     * a typo in a future route definition — must fail closed, the same as
     * a real key nobody has switched on.
     */
    public function test_a_route_gated_on_an_unknown_key_404s(): void
    {
        Route::middleware('web')->get('/__test/typo-service', fn () => response('should not be reached'))
            ->middleware('service:stays_guesthoses');

        $this->get('/__test/typo-service')->assertNotFound();
    }
}
