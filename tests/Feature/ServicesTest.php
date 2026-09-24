<?php

namespace Tests\Feature;

use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The service registry — §15.3 (Phase 8.1) of the upgrade plan.
 *
 * This is the mechanism the owner asked for above everything else in that
 * plan: a way to turn a line of business on, mark it coming soon, or take
 * it off, from the admin, without a deploy. Nothing about the middleware or
 * the Filament page matters if the registry itself gets a state wrong, so
 * this file tests the registry alone, against the `settings` table it is
 * actually stored in — not a mock of it.
 */
class ServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_catalogued_service_defaults_to_off(): void
    {
        foreach (array_keys(Services::catalogue()) as $key) {
            $this->assertTrue(Services::isOff($key), "{$key} should default to off.");
        }
    }

    public function test_a_key_outside_the_catalogue_is_off(): void
    {
        // Not "off because it happens to be off" — off because it is not a
        // real service, which the middleware relies on to fail closed
        // against a typo in a route definition.
        $this->assertTrue(Services::isOff('made_up_service'));
        $this->assertSame(Services::OFF, Services::state('made_up_service'));
    }

    public function test_saving_a_state_persists_it(): void
    {
        Services::save(['stays_guesthouses' => Services::COMING_SOON]);

        $this->assertTrue(Services::isComingSoon('stays_guesthouses'));
        $this->assertSame(Services::COMING_SOON, Services::state('stays_guesthouses'));
    }

    public function test_saving_one_service_does_not_move_the_others(): void
    {
        Services::save(['stays_guesthouses' => Services::ON]);

        $this->assertTrue(Services::isOn('stays_guesthouses'));
        $this->assertTrue(Services::isOff('stays_island_holidays'));
        $this->assertTrue(Services::isOff('stays_rooms'));
    }

    /**
     * A stored value has to survive a state later being retired, or a
     * migration renaming one, without becoming a state that does not exist.
     */
    public function test_an_unrecognised_stored_value_falls_back_to_the_default(): void
    {
        Services::save(['stays_rooms' => 'retired_state_name']);

        $this->assertSame(Services::OFF, Services::state('stays_rooms'));
    }

    /**
     * A key the caller never mentions keeps its default rather than being
     * dropped from the row — proved by saving one key and reading a key
     * that was never part of the payload at all.
     */
    public function test_an_omitted_key_keeps_its_default(): void
    {
        Services::save(['stays_guesthouses' => Services::ON]);

        $this->assertSame(Services::OFF, Services::state('stays_rooms'));
    }

    /**
     * `Services::all()` is what the admin form fills itself from. Proved by
     * planting the mistake this test would otherwise let through: a version
     * of `all()` that returned only stored keys would drop the two services
     * nobody has saved yet, and the admin form would render two fewer rows
     * than the catalogue promises.
     */
    public function test_all_returns_the_whole_catalogue_even_before_anything_is_saved(): void
    {
        $all = Services::all();

        $this->assertSame(array_keys(Services::catalogue()), array_keys($all));
    }
}
