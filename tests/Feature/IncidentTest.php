<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\Incident;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Incidents on the ground — §8.3, and the minimum §6.5 asks for.
 *
 * The two properties these tests hold: the narrative is **append-only**,
 * and an open emergency with nobody on it is **findable**. Everything else
 * is bookkeeping.
 */
class IncidentTest extends TestCase
{
    use RefreshDatabase;

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays(10),
            'date_end' => now()->addDays(24),
        ]);
    }

    // ── Shape ────────────────────────────────────────────────────────────

    public function test_an_incident_gets_a_reference_somebody_can_read_out(): void
    {
        $incident = Incident::factory()->create(['departure_id' => $this->departure()->getKey()]);

        $this->assertMatchesRegularExpression('/^INC-\d{4}-\d{4}$/', $incident->fresh()->reference);
    }

    /** A coach that does not arrive is an incident with no victim. */
    public function test_an_incident_need_not_be_about_a_person(): void
    {
        $incident = Incident::factory()->create([
            'departure_id' => $this->departure()->getKey(),
            'category' => Incident::TRANSPORT,
            'summary' => 'The coach to Madinah did not arrive.',
        ]);

        $this->assertNull($incident->traveller_id);
        $this->assertTrue($incident->isOpen());
    }

    /**
     * When it happened is not when somebody typed it. On a trip those are
     * routinely hours apart, and a report timed by the typing is useless
     * for working out what led to what.
     */
    public function test_when_it_happened_is_recorded_separately_from_when_it_was_typed(): void
    {
        $happened = now()->subHours(9);

        $incident = Incident::factory()->create([
            'departure_id' => $this->departure()->getKey(),
            'happened_at' => $happened,
        ]);

        // To the second: `happened_at` is a `timestamp`, and now() carries
        // microseconds that the column does not.
        $this->assertSame($happened->toDateTimeString(), $incident->happened_at->toDateTimeString());
        $this->assertTrue($incident->created_at->greaterThan($incident->happened_at));
    }

    public function test_an_incident_with_no_time_given_is_recorded_as_now(): void
    {
        $incident = Incident::create([
            'departure_id' => $this->departure()->getKey(),
            'severity' => Incident::MINOR,
            'category' => Incident::OTHER,
            'summary' => 'Somebody lost a bag on the way to the hotel.',
        ]);

        $this->assertNotNull($incident->happened_at);
    }

    public function test_the_person_who_typed_it_is_recorded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $incident = Incident::factory()->create(['departure_id' => $this->departure()->getKey()]);

        $this->assertSame($user->getKey(), $incident->recorded_by);
    }

    // ── The narrative is append-only ─────────────────────────────────────

    public function test_notes_are_kept_in_the_order_they_were_written(): void
    {
        $incident = Incident::factory()->create(['departure_id' => $this->departure()->getKey()]);

        $incident->notes()->create(['body' => 'Took her to the clinic.', 'created_at' => now()->subHour()]);
        $incident->notes()->create(['body' => 'Discharged, resting at the hotel.', 'created_at' => now()]);

        $this->assertSame(
            ['Took her to the clinic.', 'Discharged, resting at the hotel.'],
            $incident->fresh()->notes->pluck('body')->all(),
        );
    }

    /**
     * Resolving writes a note as well as the resolution field.
     *
     * The field says what the outcome was; the trail says it was decided,
     * by whom and when. An incident that simply changes state with no entry
     * is one nobody can reconstruct later.
     */
    public function test_resolving_records_who_said_so_and_when(): void
    {
        $user = User::factory()->create();
        $incident = Incident::factory()->serious()->create(['departure_id' => $this->departure()->getKey()]);

        $incident->resolve('Passport replaced at the embassy; she flew home with the group.', $user);

        $incident = $incident->fresh();

        $this->assertSame(Incident::RESOLVED, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertStringContainsString('Passport replaced', $incident->resolution);
        $this->assertStringContainsString('Resolved:', $incident->notes->last()->body);
        $this->assertSame($user->getKey(), $incident->notes->last()->author_id);
    }

    /**
     * Reopening keeps both entries.
     *
     * The trail is the record. Deleting the resolution note on reopening
     * would erase the fact that somebody once thought it was over, which is
     * usually the most interesting thing in the file.
     */
    public function test_reopening_keeps_the_resolution_in_the_trail(): void
    {
        $incident = Incident::factory()->create(['departure_id' => $this->departure()->getKey()]);

        $incident->resolve('Seemed to be over.');
        $incident->reopen('It was not: she was readmitted overnight.');

        $incident = $incident->fresh();

        $this->assertSame(Incident::OPEN, $incident->status);
        $this->assertNull($incident->resolved_at);
        $this->assertCount(2, $incident->notes);
        $this->assertStringContainsString('Seemed to be over', $incident->notes->first()->body);
    }

    // ── Finding the one that matters ─────────────────────────────────────

    /**
     * The one query this table exists to answer.
     *
     * A screen that cannot find an open emergency with nobody on it is a
     * diary, not an operations tool.
     */
    public function test_an_open_emergency_with_nobody_on_it_is_findable(): void
    {
        $departure = $this->departure();

        $unattended = Incident::factory()->emergency()->create(['departure_id' => $departure->getKey()]);

        // Somebody is on this one.
        Incident::factory()->emergency()->create([
            'departure_id' => $departure->getKey(),
            'assigned_to' => User::factory()->create()->getKey(),
        ]);

        // Nobody is on this one, but it is minor.
        Incident::factory()->create(['departure_id' => $departure->getKey()]);

        // Nobody is on this one, but it is over.
        $resolved = Incident::factory()->emergency()->create(['departure_id' => $departure->getKey()]);
        $resolved->resolve('Dealt with.');

        $found = Incident::unattended()->pluck('id')->all();

        $this->assertSame([$unattended->getKey()], $found);
    }

    public function test_the_open_scope_leaves_out_what_is_over(): void
    {
        $departure = $this->departure();

        $open = Incident::factory()->create(['departure_id' => $departure->getKey()]);
        Incident::factory()->create(['departure_id' => $departure->getKey()])->resolve('Done.');

        $this->assertSame([$open->getKey()], Incident::open()->pluck('id')->all());
    }

    // ── Words, not keys ──────────────────────────────────────────────────

    /**
     * Whole literal strings. A key built by concatenation cannot be checked
     * by anything and would put a raw string on the screen the day a
     * severity is added — which TranslationTest exists to stop.
     */
    public function test_every_severity_and_category_has_a_sentence(): void
    {
        foreach (Incident::SEVERITIES as $severity) {
            $incident = new Incident(['severity' => $severity]);
            $this->assertNotSame('Unknown', $incident->severityLabel(), $severity.' has no label');
        }

        foreach (Incident::CATEGORIES as $category) {
            $incident = new Incident(['category' => $category]);
            $this->assertNotSame('Unknown', $incident->categoryLabel(), $category.' has no label');
        }
    }

    /** Most serious first: this order is the screen's order. */
    public function test_the_severities_are_ordered_worst_first(): void
    {
        $this->assertSame(Incident::EMERGENCY, Incident::SEVERITIES[0]);
        $this->assertSame(Incident::MINOR, Incident::SEVERITIES[array_key_last(Incident::SEVERITIES)]);
    }

    // ── Nothing is deleted ───────────────────────────────────────────────

    /**
     * No role holds `incident.delete`, and the permission does not exist.
     *
     * Super Admin is excluded here because `Gate::before` grants it every
     * ability outright — that is the deliberate design, so that a
     * permission added later cannot lock out the one role that must never
     * be locked out. The protection against a quietly deleted incident is
     * therefore that no admin screen offers the action and no other role
     * could take it, not that the database refuses.
     */
    public function test_no_role_but_super_admin_may_delete_an_incident(): void
    {
        $this->assertNotContains('incident.delete', Access::PERMISSIONS);

        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $user = User::factory()->create()->assignRole($role);

            $this->assertFalse(
                $user->can('delete', Incident::factory()->create(['departure_id' => $this->departure()->getKey()])),
                "[{$role}] may delete an incident.",
            );
        }
    }

    /** A traveller cannot be deleted out from under the record of what happened to them. */
    public function test_a_traveller_with_an_incident_cannot_be_deleted(): void
    {
        $traveller = Traveller::factory()->create();

        Incident::factory()->create([
            'departure_id' => $this->departure()->getKey(),
            'traveller_id' => $traveller->getKey(),
        ]);

        $this->expectException(QueryException::class);

        $traveller->delete();
    }
}
