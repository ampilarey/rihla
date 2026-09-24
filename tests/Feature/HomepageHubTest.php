<?php

namespace Tests\Feature;

use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage hub — §15.1/§15.3 (Phase 8.3).
 *
 * The homepage now introduces two lines of business, not one, but Stays has
 * nothing to sell until Phase 9 and beyond. Every new piece here — the
 * "What we do" strip and the footer's Stays column — reads the same service
 * registry the nav already does, so none of it can promise a page that
 * 404s. Proved by planting a state, exactly as `StaysNavigationTest` proves
 * the nav.
 */
class HomepageHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_what_we_do_offers_umrah_alone_while_stays_is_off(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('What we do', $html);
        $this->assertStringContainsString(route('packages.index'), $html);
        $this->assertStringNotContainsString(route('stays.guesthouses'), $html);
    }

    public function test_what_we_do_adds_a_stays_card_once_a_service_is_on(): void
    {
        Services::save(['stays_island_holidays' => Services::COMING_SOON]);

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString(route('stays.island-holidays'), $html);
    }

    public function test_the_footer_carries_no_stays_column_while_every_service_is_off(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringNotContainsString('md:grid-cols-5', $html);
    }

    public function test_the_footer_grows_a_stays_column_once_a_service_is_on(): void
    {
        Services::save(['stays_rooms' => Services::ON]);

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('md:grid-cols-5', $html);
        // The desktop nav, the mobile accordion, the "What we do" strip and
        // the footer each carry their own link to it.
        $this->assertSame(4, substr_count($html, route('stays.rooms')));
    }
}
