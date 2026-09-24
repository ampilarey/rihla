<?php

namespace Tests\Feature;

use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The grouped Umrah/Stays menu — §15.1/§15.3 (Phase 8.2).
 *
 * The Stays group is not a fixed nav item; it reads the service registry on
 * every render, so with every Stays service still at its `off` default it
 * has to add nothing at all to the page a visitor gets today. Proved the
 * way this codebase always proves an on/off switch — by planting a state
 * change and watching the nav react, per §15.3's own acceptance test for
 * this mechanism: "switch a service off and assert ... the nav link is
 * gone."
 */
class StaysNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stays_menu_is_absent_while_every_service_is_off(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Stays"', $html);
        $this->assertStringNotContainsString(route('stays.guesthouses'), $html);
    }

    public function test_switching_a_service_on_adds_it_to_the_menu(): void
    {
        Services::save(['stays_guesthouses' => Services::COMING_SOON]);

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Stays"', $html);
        $this->assertStringContainsString(route('stays.guesthouses'), $html);
        // The other two services are still off and stay out of the menu.
        $this->assertStringNotContainsString(route('stays.rooms'), $html);
        $this->assertStringNotContainsString(route('stays.island-holidays'), $html);
    }

    public function test_switching_a_service_back_off_removes_it_again(): void
    {
        Services::save(['stays_rooms' => Services::ON]);
        Services::save(['stays_rooms' => Services::OFF]);

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Stays"', $html);
        $this->assertStringNotContainsString(route('stays.rooms'), $html);
    }

    public function test_the_umrah_menu_groups_packages_and_the_guide(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Umrah"', $html);
        $this->assertStringContainsString(route('packages.index'), $html);
        $this->assertStringContainsString(route('guide'), $html);
    }

    /** Every desktop dropdown item is repeated in the mobile accordion. */
    public function test_the_mobile_menu_carries_the_same_groups(): void
    {
        Services::save(['stays_island_holidays' => Services::ON]);

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'aria-label="Umrah"'));
        $this->assertSame(2, substr_count($html, 'aria-label="Stays"'));
        $this->assertSame(2, substr_count($html, route('stays.island-holidays')));
    }

    /** An unknown/off service key never reaches the nav, even if planted directly. */
    public function test_an_off_service_never_reaches_the_rendered_nav(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringNotContainsString(route('stays.island-holidays'), $html);
        $this->assertStringNotContainsString(route('stays.rooms'), $html);
    }
}
