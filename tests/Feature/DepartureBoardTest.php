<?php

namespace Tests\Feature;

use App\Filament\Pages\DepartureBoard;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Models\Person;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The departure board screen — §8.2.
 *
 * Rendered rather than status-checked: a Filament page answers 200 while a
 * closure inside it throws in its own Livewire request.
 */
class DepartureBoardTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(string $package = 'Ramadan Umrah'): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays(40),
            'date_end' => now()->addDays(54),
            'package_id' => Package::factory()->create(['title' => ['en' => $package]])->getKey(),
            'tour_leader_id' => Person::factory()->create()->getKey(),
            'scholar_id' => Person::factory()->create()->getKey(),
        ]);
    }

    // ── Who may look ─────────────────────────────────────────────────────

    public function test_operations_can_read_the_board(): void
    {
        $this->departure();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DepartureBoard::class)
            ->assertOk()
            ->assertSee('Ramadan Umrah');
    }

    public function test_operations_can_reach_it_over_http(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(DepartureBoard::getUrl())
            ->assertOk();
    }

    /**
     * The board's headline number is money owed across a departure. The
     * Content Manager edits the website, and `$content` is defined by
     * prefix — `departure.board` would have been swept in silently, which
     * is the exact incident `departure.nusuk` is commented for.
     */
    public function test_the_content_manager_cannot(): void
    {
        $this->assertFalse($this->staff(Access::CONTENT_MANAGER)->can('departure.board'));

        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(DepartureBoard::getUrl())
            ->assertForbidden();
    }

    /** Reporting holds no payment permission at all; the board is money. */
    public function test_reporting_cannot(): void
    {
        $this->assertFalse($this->staff(Access::REPORTING)->can('departure.board'));
    }

    public function test_finance_and_booking_staff_can(): void
    {
        $this->assertTrue($this->staff(Access::FINANCE)->can('departure.board'));
        $this->assertTrue($this->staff(Access::BOOKING_STAFF)->can('departure.board'));
    }

    // ── What it says ─────────────────────────────────────────────────────

    public function test_a_departure_with_nothing_outstanding_reads_as_ready(): void
    {
        $this->departure();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DepartureBoard::class)
            ->assertOk()
            ->assertSee('Nothing outstanding');
    }

    public function test_a_blocker_is_named_on_the_board(): void
    {
        $departure = $this->departure();
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create()->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DepartureBoard::class)
            ->assertOk()
            ->assertSee('One traveller cannot go yet')
            ->assertSee('Umrah permit');
    }

    /**
     * The count of blockers and of things to look at, in each section's
     * header.
     *
     * These went missing silently: the slot was named `headerEnd` and
     * Filament's section reads `afterHeader`, so Blade discarded the whole
     * badge row without a word and every header showed only a chevron. The
     * page still answered 200 and every other assertion still passed.
     */
    public function test_the_severity_badges_render(): void
    {
        $departure = $this->departure();
        // One blocker (no tour leader) and one to look at (no scholar).
        $departure->forceFill(['tour_leader_id' => null, 'scholar_id' => null])->save();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DepartureBoard::class)
            ->assertOk()
            ->assertSee('1 blocker')
            ->assertSee('1 to look at');
    }

    public function test_a_departure_with_nothing_wrong_is_badged_ready(): void
    {
        $this->departure();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DepartureBoard::class)
            ->assertOk()
            ->assertSee('Ready');
    }

    /**
     * The board looks forward only. A rooming mistake on a trip that came
     * home last March is history, not work.
     */
    public function test_a_past_departure_is_not_on_the_board(): void
    {
        Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subDays(30),
            'date_end' => now()->subDays(16),
            'package_id' => Package::factory()->create(['title' => ['en' => 'Last March']])->getKey(),
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DepartureBoard::class)
            ->assertOk()
            ->assertDontSee('Last March');
    }

    /**
     * Soonest first. The departure that leaves on Tuesday is the one to fix,
     * even if the one in March has more wrong with it.
     */
    public function test_the_soonest_departure_comes_first(): void
    {
        $this->departure('March trip')->forceFill(['date_start' => now()->addDays(120)])->save();
        $this->departure('Tuesday trip')->forceFill(['date_start' => now()->addDays(3)])->save();

        $board = Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DepartureBoard::class)
            ->instance()
            ->getBoard();

        $this->assertSame('Tuesday trip', $board->first()['departure']->package->title);
    }

    /**
     * Flights and transport have no records in this system. Reporting them
     * as fine from the absence of data is the lie this codebase has been
     * bitten by before, so the board says plainly that it cannot see them.
     */
    public function test_the_board_says_what_it_cannot_see(): void
    {
        $this->departure();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DepartureBoard::class)
            ->assertOk()
            ->assertSee('Flights and transport are not on this board');
    }
}
