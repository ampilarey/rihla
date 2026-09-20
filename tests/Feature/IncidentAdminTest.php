<?php

namespace Tests\Feature;

use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Incidents\Pages\ListIncidents;
use App\Models\Departure;
use App\Models\Incident;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The incidents screen — §8.3, and the minimum §6.5 asks for.
 *
 * Rendered rather than status-checked: a Filament page answers 200 while a
 * column closure throws in its own Livewire request.
 */
class IncidentAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays(10),
            'date_end' => now()->addDays(24),
        ]);
    }

    private function incident(array $attributes = []): Incident
    {
        return Incident::factory()->create(array_merge(
            ['departure_id' => $this->departure()->getKey()],
            $attributes,
        ));
    }

    // ── Who may look ─────────────────────────────────────────────────────

    public function test_operations_can_work_the_incidents(): void
    {
        $this->incident(['summary' => 'Fell on the marble near Safa.']);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListIncidents::class)
            ->assertOk()
            ->assertSee('Fell on the marble near Safa.');
    }

    public function test_operations_can_reach_the_screen_over_http(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(IncidentResource::getUrl('index'))
            ->assertOk();
    }

    public function test_the_content_manager_cannot(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(IncidentResource::getUrl('index'))
            ->assertForbidden();
    }

    /**
     * The tour leader is the person standing there when it happens.
     *
     * An incident that has to wait for the office to open is one recorded
     * from memory two days later, if at all — so they raise it and add to
     * it. They do not decide it is over, and they do not hand it to
     * somebody: a leader closing their own incident from the coach is how a
     * serious one stops being followed up.
     */
    public function test_the_tour_leader_raises_one_but_does_not_close_it(): void
    {
        $leader = $this->staff(Access::TOUR_LEADER);

        $this->assertTrue($leader->can('incident.create'));
        $this->assertTrue($leader->can('incident.update'));
        $this->assertFalse($leader->can('incident.resolve'));
        $this->assertFalse($leader->can('incident.assign'));

        $incident = $this->incident();

        Livewire::actingAs($leader)
            ->test(ListIncidents::class)
            ->assertOk()
            ->assertTableActionVisible('note', $incident)
            ->assertTableActionHidden('resolve', $incident)
            ->assertTableActionHidden('assign', $incident);
    }

    /** Takes the call from a family at home asking what happened. */
    public function test_pilgrim_support_reads_but_does_not_write(): void
    {
        $support = $this->staff(Access::PILGRIM_SUPPORT);

        $this->assertTrue($support->can('incident.view'));
        $this->assertFalse($support->can('incident.create'));
        $this->assertFalse($support->can('incident.update'));
    }

    // ── The narrative ────────────────────────────────────────────────────

    public function test_a_note_is_added_to_the_record(): void
    {
        $incident = $this->incident();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListIncidents::class)
            ->callTableAction('note', $incident, ['body' => 'Spoke to the hotel; they moved her to a ground floor room.'])
            ->assertHasNoTableActionErrors();

        $this->assertStringContainsString('ground floor', $incident->fresh()->notes->last()->body);
    }

    /**
     * A resolution is required.
     *
     * "Resolved" with no sentence is the state this exists to prevent: six
     * months later nobody can say what was done, and the record looks
     * complete while being useless.
     */
    public function test_closing_one_without_saying_what_was_done_is_refused(): void
    {
        $incident = $this->incident();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListIncidents::class)
            ->callTableAction('resolve', $incident, ['resolution' => ''])
            ->assertHasTableActionErrors(['resolution']);

        $this->assertTrue($incident->fresh()->isOpen());
    }

    public function test_closing_one_records_what_was_done(): void
    {
        $incident = $this->incident();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListIncidents::class)
            ->callTableAction('resolve', $incident, ['resolution' => 'Discharged the same evening; she completed the Umrah.'])
            ->assertHasNoTableActionErrors();

        $incident = $incident->fresh();

        $this->assertSame(Incident::RESOLVED, $incident->status);
        $this->assertStringContainsString('completed the Umrah', $incident->resolution);
    }

    public function test_assigning_somebody_is_written_into_the_trail(): void
    {
        $incident = $this->incident();
        $onIt = User::factory()->create(['name' => 'Aminath Rasheed']);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListIncidents::class)
            ->callTableAction('assign', $incident, ['assigned_to' => $onIt->getKey()])
            ->assertHasNoTableActionErrors();

        $incident = $incident->fresh();

        $this->assertSame($onIt->getKey(), $incident->assigned_to);
        $this->assertStringContainsString('Aminath Rasheed', $incident->notes->last()->body);
    }

    /**
     * Worst first, then most recent.
     *
     * A list sorted by date alone puts a minor lost boarding pass from an
     * hour ago above an emergency from yesterday. This was sorted by date
     * alone, under a comment that said otherwise, until somebody opened the
     * screen and read the two together.
     */
    public function test_an_older_emergency_outranks_a_newer_minor(): void
    {
        $departure = $this->departure();

        $emergency = Incident::factory()->emergency()->create([
            'departure_id' => $departure->getKey(),
            'happened_at' => now()->subDays(2),
        ]);

        Incident::factory()->create([
            'departure_id' => $departure->getKey(),
            'happened_at' => now()->subHour(),
        ]);

        $records = Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListIncidents::class)
            ->assertOk()
            ->instance()
            ->getTableRecords();

        $this->assertSame($emergency->getKey(), $records->first()->getKey());
    }

    /**
     * An open incident with nobody on it says "Nobody" as a value, not as a
     * placeholder.
     *
     * A placeholder is rendered with Filament's own muted styling and
     * ignores the column's colour, so the one cell this column exists to
     * make red came out the palest grey on the screen.
     */
    public function test_nobody_is_a_value_so_it_can_be_coloured(): void
    {
        $unattended = $this->incident();
        $onIt = Incident::factory()->create([
            'departure_id' => $unattended->departure_id,
            'assigned_to' => User::factory()->create(['name' => 'Aminath Rasheed'])->getKey(),
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListIncidents::class)
            ->assertOk()
            ->assertTableColumnStateSet('assignee.name', 'Nobody', $unattended)
            ->assertTableColumnStateSet('assignee.name', 'Aminath Rasheed', $onIt);
    }

    // ── The badge ────────────────────────────────────────────────────────

    /**
     * The badge counts open emergencies with nobody on them — not open
     * incidents. "Twelve open" is a fact nobody can act on.
     */
    public function test_the_badge_counts_unattended_emergencies_only(): void
    {
        $departure = $this->departure();

        Incident::factory()->emergency()->create(['departure_id' => $departure->getKey()]);
        Incident::factory()->emergency()->create([
            'departure_id' => $departure->getKey(),
            'assigned_to' => User::factory()->create()->getKey(),
        ]);
        Incident::factory()->serious()->create(['departure_id' => $departure->getKey()]);
        Incident::factory()->create(['departure_id' => $departure->getKey()]);

        $this->assertSame('1', IncidentResource::getNavigationBadge());
    }

    public function test_the_badge_is_absent_when_everything_has_somebody_on_it(): void
    {
        Incident::factory()->emergency()->create([
            'departure_id' => $this->departure()->getKey(),
            'assigned_to' => User::factory()->create()->getKey(),
        ]);

        $this->assertNull(IncidentResource::getNavigationBadge());
    }

    // ── The table ────────────────────────────────────────────────────────

    /**
     * Every severity renders a word.
     *
     * The rooming screen shipped a column that rendered an empty cell for
     * exactly one value, because a null state short-circuits
     * `formatStateUsing()`. This asserts the rendered cell rather than the
     * closure.
     */
    public function test_every_severity_renders_a_word_in_the_table(): void
    {
        $departure = $this->departure();

        $emergency = Incident::factory()->emergency()->create(['departure_id' => $departure->getKey()]);
        $serious = Incident::factory()->serious()->create(['departure_id' => $departure->getKey()]);
        $minor = Incident::factory()->create(['departure_id' => $departure->getKey()]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListIncidents::class)
            ->assertOk()
            ->assertTableColumnStateSet('severity', 'Emergency', $emergency)
            ->assertTableColumnStateSet('severity', 'Serious', $serious)
            ->assertTableColumnStateSet('severity', 'Minor', $minor);
    }

    /** An incident with nobody on it says so, rather than showing a blank. */
    public function test_an_incident_with_nobody_on_it_says_nobody(): void
    {
        $incident = $this->incident();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListIncidents::class)
            ->assertOk()
            ->assertSee('Nobody');

        $this->assertNull($incident->assigned_to);
    }
}
