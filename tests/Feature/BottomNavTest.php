<?php

namespace Tests\Feature;

use App\Filament\BottomNav;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use App\Support\HostRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The bar along the bottom of a phone screen in both panels: the five
 * things somebody does most, each shown only to somebody who may open it.
 */
class BottomNavTest extends TestCase
{
    use RefreshDatabase;

    /** The bar's own markup, so a label in the sidebar cannot stand in for one on the bar. */
    private function bar(string $html): ?string
    {
        return preg_match('/<nav class="rihla-bottom-nav"[^>]*>(.*?)<\/nav>/s', $html, $m) === 1 ? $m[1] : null;
    }

    /** @return list<string> */
    private function labels(string $bar): array
    {
        preg_match_all('/rihla-bottom-nav__label">([^<]+)</', $bar, $m);

        return $m[1];
    }

    public function test_operations_get_the_five_they_use_most(): void
    {
        $html = $this->actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))
            ->get('/staff')
            ->assertOk()
            ->getContent();

        $bar = $this->bar($html);

        $this->assertNotNull($bar, 'No bottom bar on the staff panel.');
        $this->assertSame(['Today', 'Bookings', 'Payments', 'Departures', 'Documents'], $this->labels($bar));
    }

    public function test_an_item_is_shown_only_to_somebody_who_may_open_it(): void
    {
        $html = $this->actingAs(User::factory()->create()->assignRole(Access::FINANCE))
            ->get('/staff')
            ->assertOk()
            ->getContent();

        $bar = $this->bar($html);

        $this->assertNotNull($bar);
        $labels = $this->labels($bar);
        $this->assertContains('Payments', $labels);
        $this->assertNotContains('Documents', $labels, 'Finance does not handle passports.');
        $this->assertLessThanOrEqual(BottomNav::MOST, count($labels));
        $this->assertGreaterThanOrEqual(BottomNav::LEAST, count($labels));
    }

    public function test_somebody_with_nothing_on_it_gets_no_bar(): void
    {
        $html = $this->actingAs(User::factory()->create()->assignRole(Access::CONTENT_MANAGER))
            ->get('/staff')
            ->assertOk()
            ->getContent();

        $this->assertNull($this->bar($html), 'A bar of one or two items is not worth the space it takes.');
    }

    public function test_the_current_page_is_marked(): void
    {
        $html = $this->actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))
            ->get('/staff/bookings')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/is-active" aria-current="page">.*?<span class="rihla-bottom-nav__label">Bookings</s', (string) $this->bar($html));
    }

    public function test_a_host_gets_their_own_five(): void
    {
        $host = Partner::factory()->commissionBased(15)->create();
        $owner = User::factory()->create();
        HostMembership::create(['partner_id' => $host->id, 'user_id' => $owner->id, 'role' => HostRole::OWNER, 'accepted_at' => now()]);

        $html = $this->actingAs($owner)->get('/host/'.$host->slug)->assertOk()->getContent();

        $bar = $this->bar($html);

        $this->assertNotNull($bar, 'No bottom bar on the host panel.');
        $this->assertSame(['Today', 'Bookings', 'Calendar', 'Rooms', 'Messages'], $this->labels($bar));

        // The dashboard's URL is a prefix of every page in the panel, so a
        // plain prefix match lit "Today" on the calendar.
        $calendar = $this->actingAs($owner)->get('/host/'.$host->slug.'/calendar')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/is-active" aria-current="page">.*?<span class="rihla-bottom-nav__label">Calendar</s', (string) $this->bar($calendar));
        $this->assertSame(1, substr_count((string) $this->bar($calendar), 'is-active'), 'One current page, not two.');
    }

    /** Phone first: the bar is the default and a min-width query removes it; nothing is corrected below a width. */
    public function test_the_bar_is_removed_by_a_min_width_query_and_never_a_max_width_one(): void
    {
        $view = File::get(resource_path('views/filament/bottom-nav.blade.php'));

        $this->assertStringContainsString('@media (min-width: 64rem)', $view);
        $this->assertStringNotContainsString('max-width', $view);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $view, 'An iPhone\'s home bar sits over the bottom 34px.');
    }
}
