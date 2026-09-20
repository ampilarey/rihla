<?php

namespace Tests\Feature;

use App\Filament\Pages\Profitability;
use App\Filament\Resources\Costs\DepartureCostResource;
use App\Filament\Resources\Costs\Pages\CreateDepartureCost;
use App\Filament\Resources\Costs\Pages\ListDepartureCosts;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureCost;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The finance screens — §8.4.
 *
 * The separation: **Finance owns both halves of the arithmetic.** It
 * already knew what came in; this is what goes out, and the margin that
 * falls out of the two. Nobody else can read the margin, because "what did
 * this journey make?" is not a number the office hands round.
 */
class FinanceAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departedJourney(): Departure
    {
        $departure = Departure::factory()->withSeats(30)->create([
            'date_start' => now()->subMonth()->startOfDay(),
            'date_end' => now()->subMonth()->addDays(14)->startOfDay(),
        ]);

        Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => 10,
            'currency' => 'MVR',
            'total_minor' => 10_000_000,
        ]);

        return $departure;
    }

    // ── Who may do what ──────────────────────────────────────────────────

    public function test_finance_records_costs_and_reads_the_margin(): void
    {
        $finance = $this->staff(Access::FINANCE);

        $this->assertTrue($finance->can('cost.create'));
        $this->assertTrue($finance->can('profit.view'));
    }

    /** "What did this journey make?" is not a number the office hands round. */
    public function test_most_roles_cannot_read_the_margin(): void
    {
        foreach ([Access::BOOKING_STAFF, Access::PILGRIM_SUPPORT, Access::TOUR_LEADER, Access::CONTENT_MANAGER] as $role) {
            $this->assertFalse(
                $this->staff($role)->can('profit.view'),
                "[{$role}] can read what each journey made.",
            );
        }
    }

    /**
     * A cost entered and thought better of is corrected, not removed: a
     * margin that changed because a row vanished is one nobody can explain.
     */
    public function test_there_is_no_cost_delete_permission(): void
    {
        $this->assertNotContains('cost.delete', Access::PERMISSIONS);
    }

    public function test_a_role_without_the_permission_cannot_open_the_screens(): void
    {
        $leader = $this->staff(Access::TOUR_LEADER);

        $this->actingAs($leader)->get(DepartureCostResource::getUrl('index'))->assertForbidden();
        $this->actingAs($leader)->get(Profitability::getUrl())->assertForbidden();
    }

    // ── The price goes through Money both ways ───────────────────────────

    /**
     * Factories run unguarded and the form does not, so this is the only
     * path that proves the conversion. The screen takes whole units; the
     * column stores minor ones.
     */
    public function test_a_cost_typed_in_whole_units_is_stored_in_minor_ones(): void
    {
        $departure = $this->departedJourney();

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(CreateDepartureCost::class)
            ->fillForm([
                'departure_id' => $departure->getKey(),
                'category' => DepartureCost::HOTEL,
                'currency' => 'SAR',
                'amount_minor' => 1_200,
                'is_per_person' => true,
                'status' => DepartureCost::PAID,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cost = DepartureCost::sole();

        $this->assertSame(120_000, $cost->amount_minor);
        $this->assertSame('SAR', $cost->currency);
        $this->assertTrue($cost->is_per_person);
        // Ten travellers on this departure.
        $this->assertSame(1_200_000, $cost->totalFor(10)->minor);
    }

    // ── What the screens show ────────────────────────────────────────────

    /**
     * The row shows the unit price and what it is multiplied by, not a
     * total that would be wrong the moment somebody joins or leaves.
     */
    public function test_the_row_says_whether_a_cost_is_per_person(): void
    {
        $departure = $this->departedJourney();

        $fixed = DepartureCost::factory()->create(['departure_id' => $departure->getKey()]);
        $perHead = DepartureCost::factory()->perPerson()->create(['departure_id' => $departure->getKey()]);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(ListDepartureCosts::class)
            ->assertOk()
            ->assertSee('Whole departure')
            ->assertSee('Per person')
            ->assertTableColumnStateSet('amount_minor', 'MVR 10,000', $fixed)
            ->assertTableColumnStateSet('amount_minor', 'MVR 10,000', $perHead);
    }

    public function test_the_profit_page_shows_a_margin_for_a_departed_journey(): void
    {
        config(['finance.rates' => ['to' => 'MVR']]);

        $departure = $this->departedJourney();

        DepartureCost::factory()->create([
            'departure_id' => $departure->getKey(),
            'amount_minor' => 4_000_000,
            'status' => DepartureCost::PAID,
        ]);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(Profitability::class)
            ->assertOk()
            ->assertSee('MVR 6,000')
            ->assertSee('10 travellers');
    }

    /** Named, not silent: a report that quietly declines to total reads as nothing to total. */
    public function test_the_page_says_why_there_is_no_single_figure(): void
    {
        config(['finance.rates' => ['to' => 'MVR']]);

        $departure = $this->departedJourney();

        DepartureCost::factory()->inCurrency('SAR', 100_000)->create([
            'departure_id' => $departure->getKey(),
        ]);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(Profitability::class)
            ->assertOk()
            ->assertSee('No single figure for this journey')
            ->assertSee('no exchange rate has been set');
    }

    /**
     * Showing the whole take as margin, with nothing said, is how a season
     * gets priced on a number that never included the hotels.
     */
    public function test_the_page_says_when_no_costs_are_recorded(): void
    {
        $this->departedJourney();

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(Profitability::class)
            ->assertOk()
            ->assertSee('No costs recorded')
            ->assertSee('which it is not');
    }

    /**
     * A journey nobody was on shows no margin at all.
     *
     * "MVR 0" beside a heading, directly above "Nobody travelled", reads
     * as "this one broke even". A screenshot caught it; no assertion did.
     */
    public function test_a_journey_nobody_travelled_on_shows_no_margin(): void
    {
        config(['finance.rates' => ['to' => 'MVR']]);

        Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subMonth()->startOfDay(),
            'date_end' => now()->subMonth()->addDays(14)->startOfDay(),
        ]);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(Profitability::class)
            ->assertOk()
            ->assertSee('Nobody travelled')
            ->assertDontSee('MVR 0');
    }

    /** A departure still selling has a forecast, not a margin. */
    public function test_a_departure_that_has_not_left_is_not_on_the_report(): void
    {
        Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addMonth()->startOfDay(),
            'date_end' => now()->addMonth()->addDays(14)->startOfDay(),
        ]);

        Livewire::actingAs($this->staff(Access::FINANCE))
            ->test(Profitability::class)
            ->assertOk()
            ->assertSee('Nothing has departed yet');
    }

    /** Three statuses, three numbers, and the page lets you pick. */
    public function test_switching_which_costs_count_changes_the_answer(): void
    {
        config(['finance.rates' => ['to' => 'MVR']]);

        $departure = $this->departedJourney();

        DepartureCost::factory()->withStatus(DepartureCost::PAID)->create([
            'departure_id' => $departure->getKey(),
            'amount_minor' => 1_000_000,
        ]);
        DepartureCost::factory()->withStatus(DepartureCost::ESTIMATED)->create([
            'departure_id' => $departure->getKey(),
            'amount_minor' => 5_000_000,
        ]);

        $page = Livewire::actingAs($this->staff(Access::FINANCE))->test(Profitability::class);

        // Everything, including the estimate: 10,000,000 − 6,000,000.
        $page->assertSee('MVR 4,000');

        $page->set('countingUpTo', DepartureCost::PAID)
            // Only what was paid: 10,000,000 − 1,000,000.
            ->assertSee('MVR 9,000');
    }
}
