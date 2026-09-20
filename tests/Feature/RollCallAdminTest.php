<?php

namespace Tests\Feature;

use App\Filament\Resources\OperationsLog\OperationsLogEntryResource;
use App\Filament\Resources\OperationsLog\Pages\ListOperationsLogEntries;
use App\Filament\Resources\RollCalls\Pages\ListRollCalls;
use App\Filament\Resources\RollCalls\RollCallResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\OperationsLogEntry;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The attendance and daily-log screens — §8.3.
 *
 * Rendered rather than status-checked: a Filament page answers 200 while a
 * column closure throws in its own Livewire request.
 */
class RollCallAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /** @return array{0: Departure, 1: Collection<int, Traveller>} */
    private function departureWithParty(int $people = 3): array
    {
        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subDays(2),
            'date_end' => now()->addDays(12),
        ]);

        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => $people,
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        $travellers = collect();

        for ($i = 0; $i < $people; $i++) {
            $traveller = Traveller::factory()->for($customer)->create(['full_name' => "Traveller {$i}"]);

            $booking->travellers()->create([
                'traveller_id' => $traveller->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $i === 0,
            ]);

            $travellers->push($traveller);
        }

        return [$departure->fresh(), $travellers];
    }

    private function headCount(Departure $departure, string $moment = 'Boarding at Velana'): RollCall
    {
        return RollCall::create([
            'departure_id' => $departure->getKey(),
            'moment' => $moment,
            'taken_at' => now(),
        ]);
    }

    // ── Who may look ─────────────────────────────────────────────────────

    public function test_operations_can_work_the_attendance(): void
    {
        [$departure] = $this->departureWithParty();
        $this->headCount($departure, 'Boarding at Velana');

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRollCalls::class)
            ->assertOk()
            ->assertSee('Boarding at Velana');
    }

    public function test_operations_can_reach_both_screens_over_http(): void
    {
        $operations = $this->staff(Access::OPERATIONS_MANAGER);

        $this->actingAs($operations)->get(RollCallResource::getUrl('index'))->assertOk();
        $this->actingAs($operations)->get(OperationsLogEntryResource::getUrl('index'))->assertOk();
    }

    public function test_the_content_manager_cannot(): void
    {
        $content = $this->staff(Access::CONTENT_MANAGER);

        $this->actingAs($content)->get(RollCallResource::getUrl('index'))->assertForbidden();
        $this->actingAs($content)->get(OperationsLogEntryResource::getUrl('index'))->assertForbidden();
    }

    /**
     * The head count is the tour leader's job — they are the one standing
     * at the coach door. Deleting a count is not: a count deleted from the
     * coach is a count nobody can check.
     */
    public function test_the_tour_leader_takes_the_count_but_cannot_delete_one(): void
    {
        $leader = $this->staff(Access::TOUR_LEADER);

        $this->assertTrue($leader->can('attendance.create'));
        $this->assertTrue($leader->can('attendance.update'));
        $this->assertFalse($leader->can('attendance.delete'));

        [$departure] = $this->departureWithParty();
        $rollCall = $this->headCount($departure);

        Livewire::actingAs($leader)
            ->test(ListRollCalls::class)
            ->assertOk()
            ->assertTableActionVisible('mark', $rollCall)
            ->assertTableActionHidden('delete', $rollCall);
    }

    /** And they write the day up, because they were there. */
    public function test_the_tour_leader_writes_the_daily_log(): void
    {
        $leader = $this->staff(Access::TOUR_LEADER);

        $this->assertTrue($leader->can('opslog.create'));
        $this->assertTrue($leader->can('opslog.update'));

        $this->actingAs($leader)->get(OperationsLogEntryResource::getUrl('index'))->assertOk();
    }

    // ── Marking ──────────────────────────────────────────────────────────

    public function test_somebody_can_be_marked_here(): void
    {
        [$departure, $travellers] = $this->departureWithParty(2);
        $rollCall = $this->headCount($departure);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRollCalls::class)
            ->callTableAction('mark', $rollCall, [
                'traveller_id' => $travellers[0]->getKey(),
                'state' => RollCallMark::PRESENT,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(RollCallMark::PRESENT, $rollCall->fresh()->marks->sole()->state);
    }

    /**
     * A leader changes a mark when somebody turns up two minutes later.
     *
     * The unique index would otherwise throw in their face at the coach
     * door, so the action updates rather than inserts.
     */
    public function test_marking_the_same_person_twice_changes_the_mark(): void
    {
        [$departure, $travellers] = $this->departureWithParty(2);
        $rollCall = $this->headCount($departure);

        $component = Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRollCalls::class);

        $component->callTableAction('mark', $rollCall, [
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::ABSENT,
        ])->assertHasNoTableActionErrors();

        $component->callTableAction('mark', $rollCall->fresh(), [
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
            'note' => 'Turned up two minutes later.',
        ])->assertHasNoTableActionErrors();

        $mark = $rollCall->fresh()->marks->sole();

        $this->assertSame(RollCallMark::PRESENT, $mark->state);
        $this->assertStringContainsString('two minutes later', $mark->note);
    }

    // ── The question the screen exists to answer ─────────────────────────

    public function test_the_modal_names_the_people_nobody_marked(): void
    {
        [$departure, $travellers] = $this->departureWithParty(3);
        $rollCall = $this->headCount($departure);

        RollCallMark::create([
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
        ]);

        $rollCall = $rollCall->fresh();

        // The view directly, the way the rooming check is tested: a
        // Filament modal's body is rendered in its own request, so mounting
        // the action yields HTML that does not contain it.
        $this->view('filament.roll-call-missing', [
            'rollCall' => $rollCall,
            'unmarked' => $rollCall->unmarked(),
            'absent' => $rollCall->marks->where('state', RollCallMark::ABSENT),
            'excused' => $rollCall->marks->where('state', RollCallMark::EXCUSED),
        ])
            ->assertSee('Nobody has marked these')
            ->assertSee('Traveller 1')
            ->assertSee('Traveller 2')
            ->assertSee('unaccounted for');

        // And the action is on the row, which is what the view test cannot
        // tell you. Not `assertHasNoTableActionErrors()`: this action has
        // no form, so Livewire has no mounted schema to look at and throws
        // PropertyNotFoundException rather than failing an assertion.
        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRollCalls::class)
            ->assertTableActionVisible('missing', $rollCall);
    }

    public function test_the_modal_says_so_when_everybody_is_accounted_for(): void
    {
        [$departure, $travellers] = $this->departureWithParty(2);
        $rollCall = $this->headCount($departure);

        foreach ($travellers as $traveller) {
            RollCallMark::create([
                'roll_call_id' => $rollCall->getKey(),
                'traveller_id' => $traveller->getKey(),
                'state' => RollCallMark::PRESENT,
            ]);
        }

        $rollCall = $rollCall->fresh();

        $this->view('filament.roll-call-missing', [
            'rollCall' => $rollCall,
            'unmarked' => $rollCall->unmarked(),
            'absent' => $rollCall->marks->where('state', RollCallMark::ABSENT),
            'excused' => $rollCall->marks->where('state', RollCallMark::EXCUSED),
        ])->assertSee('Everybody is accounted for');
    }

    // ── The table ────────────────────────────────────────────────────────

    /**
     * "Unaccounted for" reads a number or "Nobody", never a blank.
     *
     * A value rather than a placeholder, so the column's colour applies:
     * a placeholder is rendered with Filament's own muted styling and the
     * one cell that has to be red comes out the palest grey. That exact
     * defect shipped on the incidents screen.
     */
    public function test_the_unaccounted_column_reads_a_word_or_a_number(): void
    {
        [$departure, $travellers] = $this->departureWithParty(2);

        $settled = $this->headCount($departure, 'Settled');
        foreach ($travellers as $traveller) {
            RollCallMark::create([
                'roll_call_id' => $settled->getKey(),
                'traveller_id' => $traveller->getKey(),
                'state' => RollCallMark::PRESENT,
            ]);
        }

        $unsettled = $this->headCount($departure, 'Unsettled');

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListRollCalls::class)
            ->assertOk()
            ->assertTableColumnStateSet('unaccounted', 'Nobody', $settled->fresh())
            ->assertTableColumnStateSet('unaccounted', '2', $unsettled->fresh());
    }

    // ── The badge ────────────────────────────────────────────────────────

    public function test_the_badge_counts_unsettled_counts_on_live_trips(): void
    {
        [$departure] = $this->departureWithParty(2);
        $this->headCount($departure, 'Nobody marked');

        $this->assertSame('1', RollCallResource::getNavigationBadge());
    }

    /**
     * An unfinished count on a trip that came home last March is a tidying
     * job, not somebody standing in a car park.
     */
    public function test_a_count_on_a_finished_trip_is_not_on_the_badge(): void
    {
        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subMonths(6),
            'date_end' => now()->subMonths(6)->addDays(14),
        ]);

        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();
        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create()->getKey(),
            'occupancy' => 'quad',
        ]);

        $this->headCount($departure->fresh(), 'Long over');

        $this->assertNull(RollCallResource::getNavigationBadge());
    }

    // ── The daily log ────────────────────────────────────────────────────

    public function test_the_daily_log_shows_what_was_written(): void
    {
        [$departure] = $this->departureWithParty(1);

        OperationsLogEntry::create([
            'departure_id' => $departure->getKey(),
            'happened_on' => now()->subDay()->toDateString(),
            'body' => 'The coach to Madinah was forty minutes late.',
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListOperationsLogEntries::class)
            ->assertOk()
            ->assertSee('forty minutes late');
    }

    /**
     * The navigation label and the page heading are the same words.
     *
     * Without an explicit title Filament derives the heading from the model
     * label, so the navigation said "Attendance" and "Daily log" while the
     * headings and breadcrumbs said "Head Counts" and "Log Entries" — the
     * same screen with two names depending on where you looked. Found by
     * opening both pages.
     */
    public function test_each_screen_is_called_the_same_thing_everywhere(): void
    {
        $operations = $this->staff(Access::OPERATIONS_MANAGER);

        $this->actingAs($operations)
            ->get(RollCallResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Attendance')
            ->assertDontSee('Head Counts');

        $this->actingAs($operations)
            ->get(OperationsLogEntryResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Daily log')
            ->assertDontSee('Log Entries');
    }

    /** The log is not a queue, so there is nothing to drive to zero. */
    public function test_the_daily_log_carries_no_badge(): void
    {
        $this->assertNull(OperationsLogEntryResource::getNavigationBadge());
    }
}
