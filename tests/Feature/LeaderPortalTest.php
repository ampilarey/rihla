<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Incident;
use App\Models\Person;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Leader\Outbox;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Tour Leader Portal — §6.3.
 *
 * Two properties carry everything: a leader sees **only their own group**,
 * and a write made with no signal survives being replayed. The second is
 * what "must work offline" actually means once the marketing is removed.
 */
class LeaderPortalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `route()` needs the locale segment, which SetLocale only puts into
     * URL::defaults during a request — so calling route() before the first
     * one throws. This does what the middleware does, up front.
     */
    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);
    }

    private function leader(?Person $person = null): User
    {
        $user = User::factory()->create()->assignRole(Access::TOUR_LEADER);

        if ($person !== null) {
            $person->forceFill(['user_id' => $user->getKey()])->save();
        }

        return $user->fresh();
    }

    /** @return array{0: Departure, 1: Collection<int, Traveller>, 2: Person} */
    private function groupOnTheGround(int $people = 3): array
    {
        $leaderProfile = Person::factory()->create();

        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subDays(2),
            'date_end' => now()->addDays(12),
            'tour_leader_id' => $leaderProfile->getKey(),
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

        return [$departure->fresh(), $travellers, $leaderProfile];
    }

    private function headCount(Departure $departure): RollCall
    {
        return RollCall::create([
            'departure_id' => $departure->getKey(),
            'moment' => 'Boarding at Velana',
            'taken_at' => now(),
        ]);
    }

    // ── Whose group ──────────────────────────────────────────────────────

    public function test_a_leader_sees_the_group_their_profile_is_assigned_to(): void
    {
        [$departure, , $profile] = $this->groupOnTheGround();

        $this->actingAs($this->leader($profile))
            ->get(route('leader.index'))
            ->assertOk()
            ->assertSee($departure->package->title);
    }

    /**
     * A roster carries pilgrim names, ages and who is sharing a room with
     * whom. Another leader has no business reading it.
     */
    public function test_a_leader_does_not_see_somebody_elses_group(): void
    {
        [$departure] = $this->groupOnTheGround();
        $otherLeader = $this->leader(Person::factory()->create());

        $this->actingAs($otherLeader)
            ->get(route('leader.index'))
            ->assertOk()
            ->assertDontSee($departure->package->title);

        $this->actingAs($otherLeader)
            ->get(route('leader.departure', $departure))
            ->assertNotFound();
    }

    /**
     * The failure mode of a missing link has to be less access, never more.
     *
     * An unlinked account seeing every current departure would be the
     * opposite, and is exactly the shortcut that makes this kind of portal
     * a disclosure.
     */
    public function test_an_unlinked_account_sees_nothing_and_is_told_why(): void
    {
        [$departure] = $this->groupOnTheGround();

        $this->actingAs($this->leader())
            ->get(route('leader.index'))
            ->assertOk()
            ->assertDontSee($departure->package->title)
            ->assertSee('Nobody has linked your account yet');
    }

    public function test_operations_sees_every_group_on_the_ground(): void
    {
        [$departure] = $this->groupOnTheGround();

        $this->actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))
            ->get(route('leader.index'))
            ->assertOk()
            ->assertSee($departure->package->title);
    }

    public function test_the_content_manager_cannot_reach_the_portal_at_all(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Access::CONTENT_MANAGER))
            ->get(route('leader.index'))
            ->assertForbidden();
    }

    public function test_a_visitor_is_sent_to_sign_in(): void
    {
        $this->get(route('leader.index'))->assertRedirect();
    }

    // ── The count screen ─────────────────────────────────────────────────

    /**
     * Said on the screen, not only in the code.
     *
     * A leader who thinks an unmarked name is fine will stop looking for
     * that person, which is the whole failure this design exists to stop.
     */
    public function test_the_count_screen_says_that_unmarked_is_not_here(): void
    {
        [$departure, $travellers, $profile] = $this->groupOnTheGround();
        $rollCall = $this->headCount($departure);

        $this->actingAs($this->leader($profile))
            ->get(route('leader.count', [$departure, $rollCall]))
            ->assertOk()
            ->assertSee('not counted as here', false)
            ->assertSee($travellers[0]->full_name);
    }

    public function test_a_count_from_another_departure_is_not_reachable(): void
    {
        [$departure, , $profile] = $this->groupOnTheGround();
        [$other] = $this->groupOnTheGround();

        $this->actingAs($this->leader($profile))
            ->get(route('leader.count', [$departure, $this->headCount($other)]))
            ->assertNotFound();
    }

    /**
     * The floating WhatsApp and call buttons are for visitors.
     *
     * On the head count they sat directly over the "Excused" button on one
     * row, so a leader at a coach door would tap WhatsApp instead of
     * marking somebody. Found by rendering the page at phone width; no
     * status code could show it.
     */
    public function test_the_visitor_call_to_actions_are_not_over_the_count_screen(): void
    {
        [$departure, , $profile] = $this->groupOnTheGround();
        $rollCall = $this->headCount($departure);

        $this->actingAs($this->leader($profile))
            ->get(route('leader.count', [$departure, $rollCall]))
            ->assertOk()
            // The floating stack specifically, not any WhatsApp link: the
            // footer's is legitimate and overlaps nothing.
            ->assertDontSee('fixed right-4 bottom-4', false);

        // And the page a visitor sees still has it, so this is suppression
        // rather than the component having quietly stopped rendering.
        $this->get('/en')->assertOk()->assertSee('fixed right-4 bottom-4', false);
    }

    /**
     * The offline store holds pilgrim names, and the script clears it on
     * the logout form's submit — matched by `form[action$="/logout"]`.
     *
     * If that route ever gains a locale prefix or another segment, the
     * selector stops matching and a shared phone quietly keeps the roster
     * after the leader signs out. Nothing else would notice.
     */
    public function test_the_logout_form_still_matches_the_selector_that_clears_the_phone(): void
    {
        [$departure, , $profile] = $this->groupOnTheGround();

        $this->actingAs($this->leader($profile))
            ->get(route('leader.departure', $departure))
            ->assertOk()
            ->assertSee('action="'.route('logout').'"', false);

        $this->assertStringEndsWith('/logout', route('logout'));
    }

    // ── The snapshot a phone keeps ───────────────────────────────────────

    /**
     * The payload sits in a phone's storage until somebody clears it, so
     * the less of it there is, the less there is to lose with the phone.
     */
    public function test_the_snapshot_carries_names_and_rooms_and_nothing_sensitive(): void
    {
        [$departure, $travellers, $profile] = $this->groupOnTheGround();

        $travellers[0]->forceFill(['passport_number' => 'A1234567'])->save();

        $response = $this->actingAs($this->leader($profile))
            ->getJson(route('leader.snapshot', $departure))
            ->assertOk();

        $response->assertJsonPath('travellers.0.name', 'Traveller 0');
        $response->assertDontSee('A1234567');

        $body = $response->json();

        foreach ($body['travellers'] as $traveller) {
            $this->assertSame(['id', 'name', 'rooms'], array_keys($traveller));
        }
    }

    public function test_somebody_elses_snapshot_is_not_served(): void
    {
        [$departure] = $this->groupOnTheGround();

        $this->actingAs($this->leader(Person::factory()->create()))
            ->getJson(route('leader.snapshot', $departure))
            ->assertNotFound();
    }

    // ── The outbox: the offline half ─────────────────────────────────────

    /** @param array<string, mixed> $item */
    private function sync(User $actor, array $item): array
    {
        return $this->actingAs($actor)
            ->postJson(route('leader.sync'), ['items' => [$item + ['id' => 1]]])
            ->assertOk()
            ->json('results.0');
    }

    public function test_a_queued_mark_is_applied(): void
    {
        [$departure, $travellers, $profile] = $this->groupOnTheGround();
        $rollCall = $this->headCount($departure);

        $result = $this->sync($this->leader($profile), [
            'type' => Outbox::MARK,
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
            'marked_at' => now()->subHours(5)->toIso8601String(),
        ]);

        $this->assertSame(Outbox::APPLIED, $result['result'], $result['reason'] ?? '');

        $mark = $rollCall->fresh()->marks->sole();

        $this->assertSame(RollCallMark::PRESENT, $mark->state);
        // A count taken at the coach door at nine and synced at two is a
        // nine o'clock count.
        $this->assertSame(now()->subHours(5)->toDateTimeString(), $mark->marked_at->toDateTimeString());
    }

    /**
     * The phone cannot tell a request that never arrived from one whose
     * reply was lost, so it retries both.
     */
    public function test_replaying_a_mark_changes_nothing(): void
    {
        [$departure, $travellers, $profile] = $this->groupOnTheGround();
        $rollCall = $this->headCount($departure);
        $leader = $this->leader($profile);

        $item = [
            'type' => Outbox::MARK,
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
            'marked_at' => now()->subHour()->toIso8601String(),
        ];

        $this->sync($leader, $item);
        $second = $this->sync($leader, $item);

        $this->assertSame(Outbox::SUPERSEDED, $second['result']);
        $this->assertCount(1, $rollCall->fresh()->marks);
    }

    /**
     * The leader's clock decides, not the order things arrive in.
     *
     * Without this a retry of a stale attempt silently undoes a correction,
     * and the count reads wrong with nothing to show why.
     */
    public function test_a_stale_mark_arriving_late_does_not_undo_a_correction(): void
    {
        [$departure, $travellers, $profile] = $this->groupOnTheGround();
        $rollCall = $this->headCount($departure);
        $leader = $this->leader($profile);

        // The correction, made at 09:02, reaches the server first.
        $this->sync($leader, [
            'type' => Outbox::MARK,
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
            'marked_at' => now()->subMinutes(58)->toIso8601String(),
        ]);

        // The original, made at 09:00, is retried afterwards.
        $result = $this->sync($leader, [
            'type' => Outbox::MARK,
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::ABSENT,
            'marked_at' => now()->subHour()->toIso8601String(),
        ]);

        $this->assertSame(Outbox::SUPERSEDED, $result['result']);
        $this->assertSame(RollCallMark::PRESENT, $rollCall->fresh()->marks->sole()->state);
    }

    /**
     * A phone's clock can be wrong. One set weeks ahead would pin a mark
     * that nothing could ever supersede.
     */
    public function test_a_mark_from_a_phone_set_to_next_month_is_treated_as_now(): void
    {
        [$departure, $travellers, $profile] = $this->groupOnTheGround();
        $rollCall = $this->headCount($departure);

        $this->sync($this->leader($profile), [
            'type' => Outbox::MARK,
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
            'marked_at' => now()->addMonth()->toIso8601String(),
        ]);

        $this->assertTrue($rollCall->fresh()->marks->sole()->marked_at->lessThanOrEqualTo(now()));
    }

    /** An outbox is a request body like any other. It can say anything. */
    public function test_a_mark_for_somebody_on_another_trip_is_refused(): void
    {
        [$departure, , $profile] = $this->groupOnTheGround();
        [, $others] = $this->groupOnTheGround();
        $rollCall = $this->headCount($departure);

        $result = $this->sync($this->leader($profile), [
            'type' => Outbox::MARK,
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $others[0]->getKey(),
            'state' => RollCallMark::PRESENT,
        ]);

        $this->assertSame(Outbox::REFUSED, $result['result']);
        $this->assertCount(0, $rollCall->fresh()->marks);
    }

    public function test_a_mark_on_somebody_elses_departure_is_refused(): void
    {
        [$departure, $travellers] = $this->groupOnTheGround();
        $rollCall = $this->headCount($departure);

        $result = $this->sync($this->leader(Person::factory()->create()), [
            'type' => Outbox::MARK,
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
        ]);

        $this->assertSame(Outbox::REFUSED, $result['result']);
    }

    public function test_a_state_that_is_not_a_state_is_refused(): void
    {
        [$departure, $travellers, $profile] = $this->groupOnTheGround();
        $rollCall = $this->headCount($departure);

        $result = $this->sync($this->leader($profile), [
            'type' => Outbox::MARK,
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => 'probably_here',
        ]);

        $this->assertSame(Outbox::REFUSED, $result['result']);
    }

    // ── Incidents raised with no signal ──────────────────────────────────

    public function test_a_queued_incident_is_raised_once_however_often_it_is_replayed(): void
    {
        [$departure, , $profile] = $this->groupOnTheGround();
        $leader = $this->leader($profile);
        $uuid = (string) Str::uuid();

        $item = [
            'type' => Outbox::INCIDENT,
            'client_uuid' => $uuid,
            'departure_id' => $departure->getKey(),
            'severity' => Incident::SERIOUS,
            'category' => Incident::TRANSPORT,
            'summary' => 'The coach to Madinah did not arrive.',
            'happened_at' => now()->subHours(3)->toIso8601String(),
        ];

        $this->assertSame(Outbox::APPLIED, $this->sync($leader, $item)['result']);
        $this->assertSame(Outbox::DUPLICATE, $this->sync($leader, $item)['result']);
        $this->assertSame(Outbox::DUPLICATE, $this->sync($leader, $item)['result']);

        $this->assertSame(1, Incident::where('client_uuid', $uuid)->count());
        $this->assertSame(
            now()->subHours(3)->toDateTimeString(),
            Incident::where('client_uuid', $uuid)->sole()->happened_at->toDateTimeString(),
        );
    }

    /** Without an id made on the phone, a retry cannot be recognised. */
    public function test_a_queued_incident_with_no_client_id_is_refused(): void
    {
        [$departure, , $profile] = $this->groupOnTheGround();

        $result = $this->sync($this->leader($profile), [
            'type' => Outbox::INCIDENT,
            'departure_id' => $departure->getKey(),
            'severity' => Incident::MINOR,
            'summary' => 'Something.',
        ]);

        $this->assertSame(Outbox::REFUSED, $result['result']);
        $this->assertSame(0, Incident::count());
    }

    public function test_an_incident_with_nothing_said_about_it_is_refused(): void
    {
        [$departure, , $profile] = $this->groupOnTheGround();

        $result = $this->sync($this->leader($profile), [
            'type' => Outbox::INCIDENT,
            'client_uuid' => (string) Str::uuid(),
            'departure_id' => $departure->getKey(),
            'severity' => Incident::MINOR,
            'summary' => '   ',
        ]);

        $this->assertSame(Outbox::REFUSED, $result['result']);
        $this->assertSame(0, Incident::count());
    }

    /**
     * A leader may raise one but not close it, so the queue cannot be a way
     * round that.
     */
    public function test_a_queued_incident_is_open_and_unassigned(): void
    {
        [$departure, , $profile] = $this->groupOnTheGround();

        $this->sync($this->leader($profile), [
            'type' => Outbox::INCIDENT,
            'client_uuid' => (string) Str::uuid(),
            'departure_id' => $departure->getKey(),
            'severity' => Incident::EMERGENCY,
            'category' => Incident::MEDICAL,
            'summary' => 'Collapsed near Marwah.',
        ]);

        $incident = Incident::sole();

        $this->assertTrue($incident->isOpen());
        $this->assertNull($incident->assigned_to);
        $this->assertNull($incident->resolved_at);
    }

    // ── The batch ────────────────────────────────────────────────────────

    /**
     * One bad item must not throw away nine good ones.
     *
     * A leader whose count half-synced and then failed would have no way to
     * tell which half, so each item is applied in its own transaction and
     * answered by the id the phone gave it.
     */
    public function test_one_refused_item_does_not_lose_the_rest(): void
    {
        [$departure, $travellers, $profile] = $this->groupOnTheGround(3);
        $rollCall = $this->headCount($departure);

        $results = $this->actingAs($this->leader($profile))
            ->postJson(route('leader.sync'), ['items' => [
                ['id' => 'a', 'type' => Outbox::MARK, 'roll_call_id' => $rollCall->getKey(),
                    'traveller_id' => $travellers[0]->getKey(), 'state' => RollCallMark::PRESENT],
                ['id' => 'b', 'type' => Outbox::MARK, 'roll_call_id' => $rollCall->getKey(),
                    'traveller_id' => $travellers[1]->getKey(), 'state' => 'nonsense'],
                ['id' => 'c', 'type' => Outbox::MARK, 'roll_call_id' => $rollCall->getKey(),
                    'traveller_id' => $travellers[2]->getKey(), 'state' => RollCallMark::EXCUSED],
            ]])
            ->assertOk()
            ->json('results');

        $this->assertSame(['a', 'b', 'c'], array_column($results, 'id'));
        $this->assertSame(
            [Outbox::APPLIED, Outbox::REFUSED, Outbox::APPLIED],
            array_column($results, 'result'),
        );
        $this->assertCount(2, $rollCall->fresh()->marks);
    }

    public function test_an_unknown_kind_of_write_is_refused_rather_than_ignored(): void
    {
        [, , $profile] = $this->groupOnTheGround();

        $result = $this->sync($this->leader($profile), ['type' => 'delete_everything']);

        $this->assertSame(Outbox::REFUSED, $result['result']);
    }

    public function test_the_content_manager_cannot_post_to_sync(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Access::CONTENT_MANAGER))
            ->postJson(route('leader.sync'), ['items' => [['id' => 1, 'type' => Outbox::MARK]]])
            ->assertForbidden();
    }
}
