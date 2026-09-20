<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\OperationsLogEntry;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Attendance and the daily log — the rest of §8.3.
 *
 * The property every one of these holds down: **an unmarked traveller is
 * not present.** Nine of eleven marked is not "nine present"; it is a count
 * that has not been finished, and the two nobody marked are exactly the two
 * to go and look for.
 */
class RollCallTest extends TestCase
{
    use RefreshDatabase;

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subDays(2),
            'date_end' => now()->addDays(12),
        ]);
    }

    /** @return array{0: Departure, 1: Collection<int, Traveller>} */
    private function departureWithParty(int $people = 3): array
    {
        $departure = $this->departure();
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

    // Not `count()`: PHPUnit\Framework\TestCase::count() is final, and
    // overriding it is a PHP fatal error rather than a test failure. The
    // same trap as a private `put()` helper, which cost an afternoon.
    private function headCount(Departure $departure, string $moment = 'Boarding at Velana'): RollCall
    {
        return RollCall::create([
            'departure_id' => $departure->getKey(),
            'moment' => $moment,
            'taken_at' => now(),
        ]);
    }

    private function mark(RollCall $rollCall, Traveller $traveller, string $state): RollCallMark
    {
        return RollCallMark::create([
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $traveller->getKey(),
            'state' => $state,
        ]);
    }

    // ── Who it covers ────────────────────────────────────────────────────

    public function test_a_count_covers_everybody_on_a_confirmed_booking(): void
    {
        [$departure, $travellers] = $this->departureWithParty(3);

        $this->assertCount(3, $this->headCount($departure)->expected());
        $this->assertEqualsCanonicalizing(
            $travellers->pluck('id')->all(),
            $this->headCount($departure)->expected()->pluck('id')->all(),
        );
    }

    /** A draft booking is not somebody who is on the coach. */
    public function test_a_draft_booking_is_not_counted(): void
    {
        $departure = $this->departure();

        $booking = Booking::factory()->create(['departure_id' => $departure->getKey()]);
        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->create()->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        $this->assertCount(0, $this->headCount($departure->fresh())->expected());
    }

    // ── Unmarked is not present ──────────────────────────────────────────

    /**
     * The whole point.
     *
     * Two of three marked present is not a settled count: the third is the
     * person to go and look for, and a system that reports "2 present" and
     * says nothing else is how somebody gets left at an airport.
     */
    public function test_a_traveller_nobody_marked_is_unaccounted_for(): void
    {
        [$departure, $travellers] = $this->departureWithParty(3);
        $rollCall = $this->headCount($departure);

        $this->mark($rollCall, $travellers[0], RollCallMark::PRESENT);
        $this->mark($rollCall, $travellers[1], RollCallMark::PRESENT);

        $rollCall = $rollCall->fresh();

        $this->assertFalse($rollCall->isComplete());
        $this->assertFalse($rollCall->isSettled());
        $this->assertSame([$travellers[2]->getKey()], $rollCall->unmarked()->pluck('id')->all());
        $this->assertSame([$travellers[2]->getKey()], $rollCall->unaccountedFor()->pluck('id')->all());
    }

    public function test_everybody_marked_present_is_a_settled_count(): void
    {
        [$departure, $travellers] = $this->departureWithParty(3);
        $rollCall = $this->headCount($departure);

        foreach ($travellers as $traveller) {
            $this->mark($rollCall, $traveller, RollCallMark::PRESENT);
        }

        $rollCall = $rollCall->fresh();

        $this->assertTrue($rollCall->isComplete());
        $this->assertTrue($rollCall->isSettled());
        $this->assertSame([], $rollCall->unaccountedFor()->all());
    }

    /**
     * Somebody added to the departure after the count was taken shows up as
     * unmarked, which is correct: nobody has looked for them.
     */
    public function test_somebody_added_after_the_count_is_unmarked(): void
    {
        [$departure, $travellers] = $this->departureWithParty(2);
        $rollCall = $this->headCount($departure);

        foreach ($travellers as $traveller) {
            $this->mark($rollCall, $traveller, RollCallMark::PRESENT);
        }

        $this->assertTrue($rollCall->fresh()->isSettled());

        $booking = $departure->bookings()->where('status', Booking::CONFIRMED)->sole();
        $latecomer = Traveller::factory()->create(['full_name' => 'Added late']);
        $booking->travellers()->create([
            'traveller_id' => $latecomer->getKey(),
            'occupancy' => 'quad',
        ]);

        $rollCall = $rollCall->fresh();

        $this->assertFalse($rollCall->isSettled());
        $this->assertSame(['Added late'], $rollCall->unmarked()->pluck('full_name')->all());
    }

    // ── Excused is not absent ────────────────────────────────────────────

    /**
     * Somebody who stayed at the hotel with a fever, with the leader's
     * knowledge, is not missing. Folding the two together makes the screen
     * cry wolf on every trip and stop being read.
     */
    public function test_excused_is_not_unaccounted_for(): void
    {
        [$departure, $travellers] = $this->departureWithParty(2);
        $rollCall = $this->headCount($departure);

        $this->mark($rollCall, $travellers[0], RollCallMark::PRESENT);
        $this->mark($rollCall, $travellers[1], RollCallMark::EXCUSED);

        $rollCall = $rollCall->fresh();

        $this->assertTrue($rollCall->isComplete());
        $this->assertTrue($rollCall->isSettled());
    }

    public function test_absent_is_unaccounted_for(): void
    {
        [$departure, $travellers] = $this->departureWithParty(2);
        $rollCall = $this->headCount($departure);

        $this->mark($rollCall, $travellers[0], RollCallMark::PRESENT);
        $this->mark($rollCall, $travellers[1], RollCallMark::ABSENT);

        $rollCall = $rollCall->fresh();

        // Finished, but not settled: everybody has been looked at and one
        // of them is not here.
        $this->assertTrue($rollCall->isComplete());
        $this->assertFalse($rollCall->isSettled());
        $this->assertSame([$travellers[1]->getKey()], $rollCall->unaccountedFor()->pluck('id')->all());
    }

    // ── Shape ────────────────────────────────────────────────────────────

    public function test_one_mark_per_person_per_count(): void
    {
        [$departure, $travellers] = $this->departureWithParty(1);
        $rollCall = $this->headCount($departure);

        $this->mark($rollCall, $travellers[0], RollCallMark::PRESENT);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->mark($rollCall, $travellers[0], RollCallMark::ABSENT);
    }

    /** Two counts on the same trip are independent. */
    public function test_each_moment_is_counted_separately(): void
    {
        [$departure, $travellers] = $this->departureWithParty(2);

        $boarding = $this->headCount($departure, 'Boarding at Velana');
        $arrival = $this->headCount($departure, 'Off the coach in Madinah');

        foreach ($travellers as $traveller) {
            $this->mark($boarding, $traveller, RollCallMark::PRESENT);
        }

        $this->assertTrue($boarding->fresh()->isSettled());
        $this->assertFalse($arrival->fresh()->isSettled());
        $this->assertCount(2, $arrival->fresh()->unmarked());
    }

    public function test_the_person_who_took_the_count_is_recorded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $rollCall = $this->headCount($this->departure());

        $this->assertSame($user->getKey(), $rollCall->taken_by);
    }

    public function test_every_state_has_a_sentence(): void
    {
        foreach (RollCallMark::STATES as $state) {
            $this->assertNotSame('Unknown', (new RollCallMark(['state' => $state]))->stateLabel(), $state);
        }
    }

    /** A traveller cannot be deleted out from under the record of a count. */
    public function test_a_traveller_with_a_mark_cannot_be_deleted(): void
    {
        [$departure, $travellers] = $this->departureWithParty(1);
        $this->mark($this->headCount($departure), $travellers[0], RollCallMark::PRESENT);

        $this->expectException(QueryException::class);

        $travellers[0]->delete();
    }

    // ── The daily log ────────────────────────────────────────────────────

    /** A log written up the next morning is still about yesterday. */
    public function test_the_day_a_log_is_about_is_not_the_day_it_was_written(): void
    {
        $entry = OperationsLogEntry::create([
            'departure_id' => $this->departure()->getKey(),
            'happened_on' => now()->subDay()->toDateString(),
            'body' => 'The coach to Madinah was forty minutes late.',
        ]);

        $this->assertSame(now()->subDay()->toDateString(), $entry->happened_on->toDateString());
        $this->assertSame(now()->toDateString(), $entry->created_at->toDateString());
    }

    public function test_a_log_entry_records_who_wrote_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = OperationsLogEntry::create([
            'departure_id' => $this->departure()->getKey(),
            'body' => 'Quiet day.',
        ]);

        $this->assertSame($user->getKey(), $entry->recorded_by);
        $this->assertSame(now()->toDateString(), $entry->happened_on->toDateString());
    }

    /**
     * The log is the account of the trip. An entry somebody regrets is
     * corrected by writing the correction, the way a ship's log is.
     */
    public function test_no_role_but_super_admin_may_delete_a_log_entry(): void
    {
        $this->assertNotContains('opslog.delete', Access::PERMISSIONS);

        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $this->assertFalse(
                User::factory()->create()->assignRole($role)->can('opslog.delete'),
                "[{$role}] may delete a log entry.",
            );
        }
    }
}
