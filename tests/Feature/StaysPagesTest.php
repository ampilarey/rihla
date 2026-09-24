<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Stays placeholder pages — §15.3 (Phase 8.2).
 *
 * These are not the Stays engine (Phase 9 builds that); they are what
 * `EnsureServiceEnabled` lets a visitor reach today, and they exist mainly
 * to prove the registry's gate actually gates a real route rather than the
 * throwaway one `EnsureServiceEnabledTest` registers for itself.
 */
class StaysPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_off_service_404s(): void
    {
        // Every service defaults to off, so no state change is needed here.
        $this->get('/en/stays/guesthouses')->assertNotFound();
    }

    public function test_a_coming_soon_service_is_reachable(): void
    {
        Services::save(['stays_guesthouses' => Services::COMING_SOON]);

        $this->get('/en/stays/guesthouses')->assertOk()->assertSee('Guesthouses');
    }

    public function test_an_on_service_is_reachable(): void
    {
        Services::save(['stays_rooms' => Services::ON]);

        $this->get('/en/stays/rooms')->assertOk()->assertSee('Rooms in Malé');
    }

    public function test_every_stays_page_is_reachable_once_on_in_both_locales(): void
    {
        Services::save([
            'stays_guesthouses' => Services::ON,
            'stays_island_holidays' => Services::ON,
            'stays_rooms' => Services::ON,
        ]);

        foreach (['en', 'dv'] as $locale) {
            $this->get("/{$locale}/stays/guesthouses")->assertOk();
            $this->get("/{$locale}/stays/island-holidays")->assertOk();
            $this->get("/{$locale}/stays/rooms")->assertOk();
        }
    }

    /**
     * The page reuses the contact page's enquiry pipe rather than a new
     * one — a Stays lead becomes the same tracked lead an Umrah one does.
     */
    public function test_the_enquiry_form_posts_to_the_same_pipe_the_contact_page_uses(): void
    {
        Services::save(['stays_guesthouses' => Services::ON]);

        $this->post('/en/contact', [
            'name' => 'Aisha',
            'email' => 'aisha@example.com',
            'message' => 'Interested in: Guesthouses',
        ])->assertRedirect();

        $this->assertDatabaseHas('enquiries', [
            'name' => 'Aisha',
            'email' => 'aisha@example.com',
        ]);

        $this->assertSame(Enquiry::WEB, Enquiry::first()->source);
    }
}
