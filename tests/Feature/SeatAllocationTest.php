<?php

namespace Tests\Feature;

use App\Exceptions\NoSeatsAvailable;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Package;
use App\Models\SeatHold;
use App\Services\Booking\SeatAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one invariant Phase 3 must not get wrong: a departure never sells more
 * seats than it has.
 *
 * The plan (§5.2) requires this to be prevented at the database, not only in
 * PHP, so it is tested from both directions — through the allocator, which
 * is how the application does it, and by writing the counters directly,
 * which is how a console command or an import script might.
 */
class SeatAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function allocator(): SeatAllocator
    {
        return app(SeatAllocator::class);
    }

    private function departure(int $seats = 4): Departure
    {
        return Departure::factory()->withSeats($seats)->create();
    }

    public function test_holding_seats_takes_them_off_the_departure(): void
    {
        $departure = $this->departure(10);

        $hold = $this->allocator()->hold($departure, 3);

        $this->assertSame(3, $hold->seats);
        $this->assertSame(3, $departure->fresh()->capacity_held);
        $this->assertSame(7, $departure->fresh()->seats_remaining);
        $this->assertTrue($hold->isLive());
    }

    /** The caller's copy of the row is the one they will render. */
    public function test_the_passed_departure_is_left_up_to_date(): void
    {
        $departure = $this->departure(10);

        $this->allocator()->hold($departure, 2);

        $this->assertSame(2, $departure->capacity_held);
        $this->assertSame(8, $departure->seats_remaining);
    }

    public function test_the_last_seat_can_be_held_and_the_one_after_it_cannot(): void
    {
        $departure = $this->departure(2);

        $this->allocator()->hold($departure, 2);

        $this->expectException(NoSeatsAvailable::class);

        $this->allocator()->hold($departure, 1);
    }

    /**
     * Zero capacity means nobody entered one — every departure backfilled
     * from `trips` starts there. Refusing with a message that says what to
     * do beats an integrity error from the constraint underneath.
     */
    public function test_a_departure_with_no_capacity_recorded_refuses_to_sell(): void
    {
        $departure = Departure::factory()->withoutCapacity()->create();

        $this->expectExceptionMessage('no capacity recorded');

        $this->allocator()->hold($departure, 1);
    }

    public function test_a_hold_of_zero_seats_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->allocator()->hold($this->departure(), 0);
    }

    // ── The database-level backstop ───────────────────────────────────────

    /**
     * The plan's actual requirement: not just an app check.
     *
     * This writes the counters directly, the way a console command or a
     * tinker session would, bypassing every line of PHP in the allocator.
     * The database must still refuse.
     *
     * On MySQL and PostgreSQL this is a CHECK constraint; on SQLite, which
     * cannot add one to an existing table, it is an equivalent pair of
     * triggers. Both surface as a QueryException, so this test asserts the
     * same behaviour on the engine developers run and the engine production
     * runs.
     */
    public function test_the_database_refuses_an_oversold_row(): void
    {
        $departure = $this->departure(4);

        $this->expectException(QueryException::class);

        DB::table('departures')
            ->where('id', $departure->getKey())
            ->update(['capacity_confirmed' => 5]);
    }

    public function test_the_database_refuses_held_plus_confirmed_over_the_total(): void
    {
        $departure = $this->departure(4);

        $this->expectException(QueryException::class);

        DB::table('departures')
            ->where('id', $departure->getKey())
            ->update(['capacity_held' => 3, 'capacity_confirmed' => 2]);
    }

    public function test_the_database_allows_exactly_full(): void
    {
        $departure = $this->departure(4);

        DB::table('departures')
            ->where('id', $departure->getKey())
            ->update(['capacity_held' => 1, 'capacity_confirmed' => 3]);

        $this->assertSame(0, $departure->fresh()->seats_remaining);
    }

    public function test_the_database_refuses_an_oversold_row_on_insert(): void
    {
        $this->expectException(QueryException::class);

        DB::table('departures')->insert([
            'package_id' => Package::factory()->create()->getKey(),
            'date_start' => now()->addMonth()->toDateString(),
            'date_end' => now()->addMonth()->addDays(14)->toDateString(),
            'capacity_total' => 2,
            'capacity_held' => 0,
            'capacity_confirmed' => 3,
            'status' => Departure::STATUS_UPCOMING,
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Confirming ────────────────────────────────────────────────────────

    public function test_confirming_moves_seats_from_held_to_confirmed(): void
    {
        $departure = $this->departure(10);
        $hold = $this->allocator()->hold($departure, 2);

        $this->allocator()->confirm($hold);

        $departure->refresh();
        $this->assertSame(0, $departure->capacity_held);
        $this->assertSame(2, $departure->capacity_confirmed);
        $this->assertSame(8, $departure->seats_remaining);
        $this->assertNotNull($hold->fresh()->confirmed_at);
    }

    /** A payment callback that arrives twice must not confirm twice. */
    public function test_confirming_is_idempotent(): void
    {
        $departure = $this->departure(10);
        $hold = $this->allocator()->hold($departure, 2);

        $this->allocator()->confirm($hold);
        $this->allocator()->confirm($hold);

        $this->assertSame(2, $departure->fresh()->capacity_confirmed);
    }

    /**
     * The interesting case: the hold lapsed at 15:00 and the payment landed
     * at 15:01. The seats went back, so they are asked for again — and if
     * somebody else took them in the meantime, this fails. Better found
     * here, where the money can be refunded, than at check-in.
     */
    public function test_a_lapsed_hold_can_still_confirm_if_the_seats_are_free(): void
    {
        $departure = $this->departure(10);
        $hold = $this->allocator()->hold($departure, 2, expiresAt: now()->addMinutes(15));

        $this->travel(16)->minutes();

        $this->allocator()->confirm($hold);

        $departure->refresh();
        $this->assertSame(2, $departure->capacity_confirmed);
        $this->assertSame(0, $departure->capacity_held);
    }

    public function test_a_lapsed_hold_cannot_confirm_seats_somebody_else_took(): void
    {
        $departure = $this->departure(2);
        $mine = $this->allocator()->hold($departure, 2, expiresAt: now()->addMinutes(15));

        $this->travel(16)->minutes();

        // Somebody else takes the seats the moment they are back.
        $this->allocator()->hold($departure, 2);

        $this->expectException(NoSeatsAvailable::class);

        $this->allocator()->confirm($mine);
    }

    // ── Releasing and expiry ──────────────────────────────────────────────

    public function test_releasing_a_hold_gives_the_seats_back(): void
    {
        $departure = $this->departure(10);
        $hold = $this->allocator()->hold($departure, 3);

        $this->allocator()->release($hold);

        $this->assertSame(0, $departure->fresh()->capacity_held);
        $this->assertNotNull($hold->fresh()->released_at);
        $this->assertSame(SeatHold::CANCELLED, $hold->fresh()->released_reason);
    }

    public function test_releasing_twice_does_not_give_the_seats_back_twice(): void
    {
        $departure = $this->departure(10);
        $hold = $this->allocator()->hold($departure, 3);
        $second = $this->allocator()->hold($departure, 2);

        $this->allocator()->release($hold);
        $this->allocator()->release($hold);

        $this->assertSame(2, $departure->fresh()->capacity_held, 'The second hold must survive.');
        $this->assertNull($second->fresh()->released_at, 'The second hold must not have been released.');
    }

    /**
     * Correctness must not depend on cron. The next person to book reclaims
     * the lapsed hold inside the same lock, whether or not the scheduler has
     * ever run on this host.
     */
    public function test_a_lapsed_hold_is_reclaimed_by_the_next_booking(): void
    {
        $departure = $this->departure(2);
        $this->allocator()->hold($departure, 2, expiresAt: now()->addMinutes(15));

        $this->assertSame(0, $departure->fresh()->seats_remaining);

        $this->travel(16)->minutes();

        $hold = $this->allocator()->hold($departure, 2);

        $this->assertSame(2, $hold->seats);
        $this->assertSame(2, $departure->fresh()->capacity_held, 'Only the new hold may be counted.');
    }

    public function test_the_command_reclaims_lapsed_holds(): void
    {
        $departure = $this->departure(4);
        $this->allocator()->hold($departure, 3, expiresAt: now()->addMinutes(15));

        $this->travel(16)->minutes();

        $this->artisan('bookings:expire-holds')->assertSuccessful();

        $this->assertSame(0, $departure->fresh()->capacity_held);
    }

    public function test_the_command_says_so_when_there_is_nothing_to_do(): void
    {
        $this->artisan('bookings:expire-holds')
            ->expectsOutputToContain('No seat holds have lapsed.')
            ->assertSuccessful();
    }

    /** A live hold is not swept. */
    public function test_the_command_leaves_live_holds_alone(): void
    {
        $departure = $this->departure(4);
        $this->allocator()->hold($departure, 3);

        $this->artisan('bookings:expire-holds')->assertSuccessful();

        $this->assertSame(3, $departure->fresh()->capacity_held);
    }

    /** A booking whose hold lapsed must not go on claiming it holds seats. */
    public function test_a_lapsed_hold_expires_its_booking(): void
    {
        $departure = $this->departure(4);
        $booking = Booking::factory()->create(['departure_id' => $departure->getKey(), 'seats' => 2]);

        $hold = $this->allocator()->hold($departure, 2, $booking, now()->addMinutes(15));
        $booking->transitionTo(Booking::HELD);

        $this->travel(16)->minutes();
        $this->allocator()->reclaim($departure);

        $this->assertSame(Booking::EXPIRED, $booking->fresh()->status);
        $this->assertSame(SeatHold::EXPIRED, $hold->fresh()->released_reason);
    }

    // ── Cancelling a confirmed booking ────────────────────────────────────

    /**
     * Confirmed seats are not held seats. Subtracting a cancellation from
     * the wrong counter would leave the departure permanently, invisibly
     * full — and `capacity_held` cannot go below zero, so the error would
     * not even show up as a negative number.
     */
    public function test_cancelling_a_confirmed_booking_returns_its_seats(): void
    {
        $departure = $this->departure(10);
        $booking = Booking::factory()->create(['departure_id' => $departure->getKey(), 'seats' => 2]);

        $hold = $this->allocator()->hold($departure, 2, $booking);
        $this->allocator()->confirm($hold);

        $this->allocator()->releaseConfirmed($booking);

        $departure->refresh();
        $this->assertSame(0, $departure->capacity_confirmed);
        $this->assertSame(0, $departure->capacity_held);
        $this->assertSame(10, $departure->seats_remaining);
    }
}
