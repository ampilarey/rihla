<?php

namespace Tests\Feature;

use App\Filament\Resources\Checklists\DepartureChecklistItemResource;
use App\Filament\Resources\Checklists\Pages\ListDepartureChecklistItems;
use App\Models\Departure;
use App\Models\DepartureChecklistItem;
use App\Models\User;
use App\Support\Access;
use App\Support\Anonymisation;
use App\Support\DepartureReadiness;
use App\Support\Forgetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pre-departure checklists — §8.3.
 *
 * Nothing is pre-filled: the list is the office's, and an invented list of
 * plausible steps is the mistake this codebase keeps having to take back
 * out. What is tested is who may change the list, who may tick it, and
 * what the departure board makes of a line that is late.
 */
class DepartureChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(int $inDays = 21): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays($inDays),
            'date_end' => now()->addDays($inDays + 14),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function item(Departure $departure, array $overrides = []): DepartureChecklistItem
    {
        return DepartureChecklistItem::create(array_merge([
            'departure_id' => $departure->getKey(),
            'title' => 'Group visa submitted',
            'due_on' => now()->subDays(2)->toDateString(),
            'is_blocking' => false,
        ], $overrides));
    }

    /** @return array<int, array{area: string, severity: string, headline: string, detail: string}> */
    private function checklistConcerns(Departure $departure): array
    {
        return array_values(array_filter(
            DepartureReadiness::concerns($departure),
            fn (array $c): bool => $c['area'] === DepartureReadiness::CHECKLIST,
        ));
    }

    // ── The departure board ──────────────────────────────────────────────

    public function test_nothing_is_pre_filled(): void
    {
        $this->assertSame(0, DepartureChecklistItem::count());
        $this->assertSame([], $this->checklistConcerns($this->departure()));
    }

    public function test_an_overdue_line_is_named(): void
    {
        $departure = $this->departure();
        $this->item($departure);

        $concerns = $this->checklistConcerns($departure);

        $this->assertCount(1, $concerns);
        $this->assertSame('Overdue: Group visa submitted', $concerns[0]['headline']);
        $this->assertSame(DepartureReadiness::ATTENTION, $concerns[0]['severity']);
    }

    /** Blocking only because somebody said so. */
    public function test_an_overdue_blocking_line_blocks(): void
    {
        $departure = $this->departure();
        $this->item($departure, ['is_blocking' => true]);

        $this->assertSame(DepartureReadiness::BLOCKING, $this->checklistConcerns($departure)[0]['severity']);
        $this->assertTrue(DepartureReadiness::hasBlockers($departure));
    }

    public function test_a_done_line_and_a_line_not_yet_due_are_silent(): void
    {
        $departure = $this->departure();
        $this->item($departure, ['title' => 'Due next week', 'due_on' => now()->addWeek()->toDateString()]);
        $this->item($departure, ['title' => 'No date', 'due_on' => null]);
        $this->item($departure)->markDone($this->staff(Access::OPERATIONS_MANAGER));

        $this->assertSame([], $this->checklistConcerns($departure));
    }

    // ── Who may do what ──────────────────────────────────────────────────

    public function test_operations_writes_the_list(): void
    {
        $departure = $this->departure();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListDepartureChecklistItems::class)
            ->callAction('create', data: [
                'departure_id' => $departure->getKey(),
                'title' => 'Rooming list sent to the Makkah hotel',
                'due_on' => now()->addDays(7)->toDateString(),
                'is_blocking' => true,
            ])
            ->assertHasNoActionErrors();

        $item = DepartureChecklistItem::sole();
        $this->assertTrue($item->is_blocking);
        $this->assertFalse($item->isDone());
    }

    /**
     * The person whose line it is ticks it — and is recorded as the one who
     * did, by the server, not by anything the form sent.
     */
    public function test_visa_staff_tick_a_line_and_are_recorded_as_having_done_it(): void
    {
        $visaStaff = $this->staff(Access::VISA_STAFF);
        $item = $this->item($this->departure());

        Livewire::actingAs($visaStaff)
            ->test(ListDepartureChecklistItems::class)
            ->assertTableActionHidden('edit', $item)
            ->assertTableActionHidden('delete', $item)
            ->assertActionHidden('create')
            ->callTableAction('tick', $item);

        $item->refresh();
        $this->assertTrue($item->isDone());
        $this->assertSame($visaStaff->getKey(), $item->done_by);
    }

    public function test_done_by_cannot_be_written_through_the_form(): void
    {
        $item = new DepartureChecklistItem(['done_at' => now(), 'done_by' => 1]);

        $this->assertNull($item->done_at);
        $this->assertNull($item->done_by);
    }

    public function test_pilgrim_support_reads_and_cannot_tick(): void
    {
        $item = $this->item($this->departure());

        Livewire::actingAs($this->staff(Access::PILGRIM_SUPPORT))
            ->test(ListDepartureChecklistItems::class)
            ->assertCanSeeTableRecords([$item])
            ->assertTableActionHidden('tick', $item);
    }

    public function test_the_content_manager_does_not_see_it(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(DepartureChecklistItemResource::getUrl('index'))
            ->assertForbidden();
    }

    // ── Copying a list ───────────────────────────────────────────────────

    /**
     * "Three weeks before" stays three weeks before, and nothing arrives
     * ticked whatever state it was in on the original.
     */
    public function test_a_list_copies_with_its_dates_moved_and_nothing_ticked(): void
    {
        $from = $this->departure(inDays: 10);
        $to = $this->departure(inDays: 40);

        $done = $this->item($from, ['due_on' => $from->date_start->copy()->subDays(21)->toDateString()]);
        $done->markDone($this->staff(Access::OPERATIONS_MANAGER));
        $this->item($from, ['title' => 'Welcome packs printed', 'due_on' => null]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListDepartureChecklistItems::class)
            ->callAction('copy', data: ['from' => $from->getKey(), 'to' => $to->getKey()])
            ->assertHasNoActionErrors();

        $copied = DepartureChecklistItem::where('departure_id', $to->getKey())->orderBy('id')->get();

        $this->assertCount(2, $copied);
        $this->assertSame(
            $to->date_start->copy()->subDays(21)->toDateString(),
            $copied[0]->due_on->toDateString(),
        );
        $this->assertNull($copied[1]->due_on);
        $this->assertTrue($copied->every(fn (DepartureChecklistItem $i): bool => ! $i->isDone()));
    }

    public function test_a_list_cannot_be_copied_onto_itself(): void
    {
        $departure = $this->departure();
        $this->item($departure);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListDepartureChecklistItems::class)
            ->callAction('copy', data: ['from' => $departure->getKey(), 'to' => $departure->getKey()])
            ->assertHasActionErrors(['to']);

        $this->assertSame(1, DepartureChecklistItem::count());
    }

    // ── Housekeeping ─────────────────────────────────────────────────────

    public function test_it_is_classified_for_scrubbing_and_forgetting(): void
    {
        $this->assertContains('departure_checklist_items', Anonymisation::classified());
        $this->assertSame([], Forgetting::unreached());
    }

    public function test_the_badge_counts_what_is_late(): void
    {
        $departure = $this->departure();
        $this->item($departure);
        $this->item($departure, ['title' => 'Later', 'due_on' => now()->addWeek()->toDateString()]);

        $this->assertSame('1', DepartureChecklistItemResource::getNavigationBadge());
    }
}
