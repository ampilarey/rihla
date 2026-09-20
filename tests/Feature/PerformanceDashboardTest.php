<?php

namespace Tests\Feature;

use App\Filament\Pages\Performance;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Enquiry;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The executive dashboard screen — §8.5.
 *
 * Two things a status code cannot see, and which this therefore asserts on
 * the rendered output:
 *
 * 1. **The named absences reach the page.** A dashboard that computes them
 *    and then renders only the measured ones is a dashboard that silently
 *    drops four of §10.5's thirteen KPIs, and it would still answer 200.
 * 2. **A reader without `profit.view` is told the margin is missing**,
 *    rather than shown a list one row shorter with no explanation.
 */
class PerformanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    // ── Who may open it ──────────────────────────────────────────────────

    public function test_the_reporting_role_can_open_the_dashboard(): void
    {
        $reporting = $this->staff(Access::REPORTING);

        $this->assertTrue($reporting->can('kpi.view'));
        $this->actingAs($reporting)->get(Performance::getUrl())->assertSuccessful();
    }

    public function test_operations_and_finance_can_open_it_too(): void
    {
        foreach ([Access::OPERATIONS_MANAGER, Access::FINANCE] as $role) {
            $this->assertTrue($this->staff($role)->can('kpi.view'), "[{$role}] cannot read the dashboard.");
        }
    }

    /** It is the whole shape of the business; it is not for everybody. */
    public function test_front_line_roles_cannot_open_it(): void
    {
        foreach ([Access::BOOKING_STAFF, Access::PILGRIM_SUPPORT, Access::TOUR_LEADER, Access::SCHOLAR] as $role) {
            $staff = $this->staff($role);

            $this->assertFalse($staff->can('kpi.view'), "[{$role}] can read the dashboard.");
            $this->actingAs($staff)->get(Performance::getUrl())->assertForbidden();
        }
    }

    // ── What actually renders ────────────────────────────────────────────

    /**
     * The four absences are on the screen, in their own words.
     *
     * This is the assertion that stops them being quietly dropped from the
     * view while the class still computes them — the page would go on
     * answering 200 either way.
     */
    public function test_the_uninstrumented_kpis_are_printed_and_not_dropped(): void
    {
        $this->actingAs($this->staff(Access::REPORTING))
            ->get(Performance::getUrl())
            ->assertSuccessful()
            ->assertSee('Not measured — nothing records these', false)
            ->assertSee('Visit → enquiry', false)
            ->assertSee('Portal weekly-active pilgrims')
            ->assertSee('Family-portal engagement')
            ->assertSee('NPS after return');
    }

    /** The reason is on the screen too, not only the name of the gap. */
    public function test_the_page_says_why_a_last_seen_stamp_is_not_a_weekly_active_count(): void
    {
        $this->actingAs($this->staff(Access::REPORTING))
            ->get(Performance::getUrl())
            ->assertSee('overwritten on every visit', false);
    }

    /**
     * Reporting holds `kpi.view` and not `profit.view`, so the margin row
     * is absent — and the page says so where the row would have been.
     */
    public function test_a_reader_without_profit_view_is_told_the_margin_is_withheld(): void
    {
        $reporting = $this->staff(Access::REPORTING);

        $this->assertFalse($reporting->can('profit.view'));

        $this->actingAs($reporting)
            ->get(Performance::getUrl())
            ->assertSee('Margin per traveller is not shown to your role');
    }

    public function test_finance_sees_the_margin_row_instead_of_the_notice(): void
    {
        $this->actingAs($this->staff(Access::FINANCE))
            ->get(Performance::getUrl())
            ->assertSee('Margin per traveller')
            ->assertDontSee('Margin per traveller is not shown to your role');
    }

    // ── The window control ───────────────────────────────────────────────

    public function test_changing_the_window_changes_which_rows_are_counted(): void
    {
        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subDays(20)->startOfDay(),
            'date_end' => now()->subDays(10)->startOfDay(),
        ]);

        $booking = Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => 2,
            'total_minor' => 1_000_000,
        ]);

        // Old enough to be outside 30 days, inside 365.
        Enquiry::factory()->create([
            'created_at' => now()->subDays(200),
            'status' => Enquiry::WON,
            'booking_id' => $booking->getKey(),
        ]);

        $page = Livewire::actingAs($this->staff(Access::REPORTING))->test(Performance::class);

        $page->assertSet('days', 90);
        $this->assertNull(
            $page->instance()->getBoard()->kpis->firstWhere('key', 'enquiry.to.booking')->figure,
        );

        $page->set('days', 365);

        $this->assertSame(
            '100%',
            $page->instance()->getBoard()->kpis->firstWhere('key', 'enquiry.to.booking')->figure,
        );
    }

    /**
     * A quiet window prints the reason, not a nought.
     *
     * The same guarantee as the read model's, asserted on what a person
     * actually reads — the place the defect would be seen.
     */
    public function test_an_empty_window_reads_as_nothing_to_measure_on_the_page(): void
    {
        $this->actingAs($this->staff(Access::REPORTING))
            ->get(Performance::getUrl())
            ->assertSee('Nothing to measure yet')
            ->assertSee('quiet window');
    }
}
