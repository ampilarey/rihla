<?php

namespace Tests\Feature;

use App\Exceptions\RoomNotAvailable;
use App\Models\BlockedDate;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Stays\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a room costs and whether anybody can have it — §15.4 (Phase 9.2).
 *
 * The arithmetic half. `StayAllocationTest` covers taking the dates and
 * `StayLockTest` proves the lock that makes it safe; those are separate
 * files because they fail for different reasons and a name that covered all
 * three would tell nobody which.
 *
 * Nearly everything here is really about one question: **which nights does
 * a stay occupy?** The dates are half-open — check-in is slept, check-out
 * is not — and reading that the other way double-books every changeover
 * day in the calendar while looking entirely correct.
 */
class StayAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Availability $availability;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->availability = app(Availability::class);

        $property = Property::factory()->create([
            'currency' => 'USD',
            'min_nights' => 1,
        ]);

        $this->room = RoomType::factory()->create([
            'property_id' => $property->id,
            'quantity' => 1,
            'base_rate_minor' => 10000,
        ]);
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    // ── Half-open dates ──────────────────────────────────────────────────

    public function test_a_stay_sleeps_every_night_but_the_last_date(): void
    {
        $nights = $this->availability->nights($this->date('2027-03-03'), $this->date('2027-03-05'));

        $this->assertSame(
            ['2027-03-03', '2027-03-04'],
            array_map(fn (CarbonImmutable $night): string => $night->toDateString(), $nights),
        );
    }

    public function test_a_range_that_ends_where_it_starts_is_no_nights(): void
    {
        $this->assertSame([], $this->availability->nights($this->date('2027-03-03'), $this->date('2027-03-03')));
    }

    /** A date picker produces this while somebody is still choosing. */
    public function test_a_backwards_range_is_no_nights_rather_than_an_error(): void
    {
        $this->assertSame([], $this->availability->nights($this->date('2027-03-05'), $this->date('2027-03-03')));
    }

    /**
     * The changeover day, which is the whole reason the dates are half-open.
     *
     * Somebody leaves on the 5th and somebody else arrives on the 5th. They
     * share no night, and refusing the second booking would cost the
     * guesthouse a night it can genuinely sell — on a one-room property,
     * every single changeover.
     */
    public function test_one_guest_may_arrive_on_the_day_another_leaves(): void
    {
        Stay::factory()->held()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
        ]);

        $this->assertTrue(
            $this->availability->isAvailable($this->room, $this->date('2027-03-05'), $this->date('2027-03-07')),
        );
    }

    /** And the mirror: leaving on the 5th does not free the 4th. */
    public function test_a_guest_may_not_arrive_the_night_before_another_leaves(): void
    {
        Stay::factory()->held()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
        ]);

        $this->assertFalse(
            $this->availability->isAvailable($this->room, $this->date('2027-03-04'), $this->date('2027-03-06')),
        );
    }

    // ── What counts as taken ─────────────────────────────────────────────

    /**
     * The list that *is* the rule.
     *
     * Asserted directly rather than only through its consequences: a status
     * added later that should hold dates and is left out of OCCUPYING
     * oversells the room with no other test failing anywhere.
     */
    public function test_only_held_and_confirmed_take_a_room_off_the_calendar(): void
    {
        $this->assertSame([Stay::HELD, Stay::CONFIRMED], Stay::OCCUPYING);

        // Every status, put on a real stay and measured. Asserting the
        // membership of the constant alone would pass for a status that is
        // in the list and still fails to block, or out of it and blocks
        // anyway — the list is only worth anything if it matches behaviour.
        foreach (Stay::STATUSES as $status) {
            Stay::query()->delete();

            Stay::factory()->create([
                'room_type_id' => $this->room->id,
                'property_id' => $this->room->property_id,
                'check_in' => '2027-03-03',
                'check_out' => '2027-03-05',
                'status' => $status,
            ]);

            $free = $this->availability->isAvailable(
                $this->room,
                $this->date('2027-03-03'),
                $this->date('2027-03-05'),
            );

            $this->assertSame(
                ! in_array($status, Stay::OCCUPYING, true),
                $free,
                "A {$status} stay ".($free ? 'left the room free' : 'took the room').', which contradicts Stay::OCCUPYING.',
            );
        }
    }

    /**
     * §15.2 decision 1: a request costs the customer nothing until a real
     * room is theirs — so it cannot take the room from anybody else either.
     * Two people may ask for the last room and the partner decides.
     */
    public function test_a_request_does_not_take_the_dates(): void
    {
        Stay::factory()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
            'status' => Stay::REQUESTED,
        ]);

        $this->assertTrue(
            $this->availability->isAvailable($this->room, $this->date('2027-03-03'), $this->date('2027-03-05')),
        );
    }

    public function test_a_confirmed_stay_takes_the_dates(): void
    {
        Stay::factory()->confirmed()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
        ]);

        $this->assertFalse(
            $this->availability->isAvailable($this->room, $this->date('2027-03-03'), $this->date('2027-03-05')),
        );
    }

    public function test_a_cancelled_stay_gives_the_dates_back(): void
    {
        Stay::factory()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
            'status' => Stay::CANCELLED,
        ]);

        $this->assertTrue(
            $this->availability->isAvailable($this->room, $this->date('2027-03-03'), $this->date('2027-03-05')),
        );
    }

    /** More than one of a room means more than one booking on the same night. */
    public function test_a_room_with_three_of_it_takes_three_stays(): void
    {
        $this->room->update(['quantity' => 3]);

        for ($i = 0; $i < 2; $i++) {
            Stay::factory()->held()->create([
                'room_type_id' => $this->room->id,
                'property_id' => $this->room->property_id,
                'check_in' => '2027-03-03',
                'check_out' => '2027-03-05',
            ]);
        }

        $this->assertTrue(
            $this->availability->isAvailable($this->room->fresh(), $this->date('2027-03-03'), $this->date('2027-03-05')),
        );

        Stay::factory()->held()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
        ]);

        $this->assertFalse(
            $this->availability->isAvailable($this->room->fresh(), $this->date('2027-03-03'), $this->date('2027-03-05')),
        );
    }

    /**
     * One full night in the middle is enough to refuse the whole stay.
     *
     * A guest cannot be moved out for a night and back in, so partial
     * availability is no availability — and this is the case a per-range
     * check rather than a per-night one would get wrong.
     */
    public function test_a_single_full_night_in_the_middle_refuses_the_range(): void
    {
        Stay::factory()->held()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-05',
            'check_out' => '2027-03-06',
        ]);

        $this->assertFalse(
            $this->availability->isAvailable($this->room, $this->date('2027-03-03'), $this->date('2027-03-08')),
        );
    }

    /** A stay re-checked against itself is not its own competitor. */
    public function test_a_stay_does_not_block_its_own_dates(): void
    {
        $stay = Stay::factory()->held()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
        ]);

        $this->assertTrue(
            $this->availability->isAvailable(
                $this->room,
                $this->date('2027-03-03'),
                $this->date('2027-03-05'),
                $stay,
            ),
        );
    }

    // ── Blocked nights ───────────────────────────────────────────────────

    public function test_a_blocked_night_is_not_for_sale(): void
    {
        BlockedDate::factory()->create([
            'room_type_id' => $this->room->id,
            'date' => '2027-03-04',
        ]);

        $this->assertFalse(
            $this->availability->isAvailable($this->room, $this->date('2027-03-03'), $this->date('2027-03-06')),
        );
    }

    /**
     * A block on the check-out date blocks nothing: that night is not slept.
     *
     * The same half-open rule as occupancy, and the case most likely to be
     * got wrong separately — the two are different code paths.
     */
    public function test_a_block_on_the_check_out_date_does_not_refuse_the_stay(): void
    {
        BlockedDate::factory()->create([
            'room_type_id' => $this->room->id,
            'date' => '2027-03-05',
        ]);

        $this->assertTrue(
            $this->availability->isAvailable($this->room, $this->date('2027-03-03'), $this->date('2027-03-05')),
        );
    }

    /** The refusal says which reason, because they lead to different answers. */
    public function test_the_refusal_distinguishes_blocked_from_taken(): void
    {
        BlockedDate::factory()->create(['room_type_id' => $this->room->id, 'date' => '2027-03-03']);

        try {
            $this->availability->assertAvailable($this->room, $this->date('2027-03-03'), $this->date('2027-03-05'));
            $this->fail('A blocked night should refuse the stay.');
        } catch (RoomNotAvailable $refusal) {
            $this->assertStringContainsString('blocked', $refusal->getMessage());
            $this->assertStringContainsString('2027-03-03', $refusal->getMessage());
        }
    }

    // ── Quantity of zero ─────────────────────────────────────────────────

    /**
     * Nobody has entered a count. That is not "unlimited" — it is exactly
     * the situation this class exists to prevent, so it refuses and the
     * message says what to do.
     */
    public function test_a_room_with_no_quantity_recorded_sells_nothing(): void
    {
        $this->room->update(['quantity' => 0]);

        try {
            $this->availability->assertAvailable($this->room->fresh(), $this->date('2027-03-03'), $this->date('2027-03-05'));
            $this->fail('A room with no quantity should refuse every night.');
        } catch (RoomNotAvailable $refusal) {
            $this->assertStringContainsString('no quantity recorded', $refusal->getMessage());
        }
    }

    // ── Minimum nights ───────────────────────────────────────────────────

    public function test_the_property_minimum_is_enforced(): void
    {
        $this->room->property->update(['min_nights' => 3]);

        $this->assertFalse(
            $this->availability->isAvailable($this->room->fresh(), $this->date('2027-03-03'), $this->date('2027-03-05')),
        );

        $this->assertTrue(
            $this->availability->isAvailable($this->room->fresh(), $this->date('2027-03-03'), $this->date('2027-03-06')),
        );
    }

    /**
     * A season may raise the minimum, and the **highest** wins.
     *
     * A five-night minimum over new year exists to stop a two-night
     * booking. Letting an adjacent ordinary season lower it back would
     * defeat the only reason anybody sets one.
     */
    public function test_the_highest_minimum_wins_when_seasons_overlap(): void
    {
        Rate::factory()->create([
            'room_type_id' => $this->room->id,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'rate_minor' => 12000,
            'min_nights' => 2,
        ]);

        Rate::factory()->create([
            'room_type_id' => $this->room->id,
            'starts_on' => '2027-03-01',
            'ends_on' => '2027-03-10',
            'rate_minor' => 30000,
            'min_nights' => 5,
        ]);

        $this->assertFalse(
            $this->availability->isAvailable($this->room, $this->date('2027-03-03'), $this->date('2027-03-06')),
        );

        $this->assertTrue(
            $this->availability->isAvailable($this->room, $this->date('2027-03-03'), $this->date('2027-03-08')),
        );
    }

    // ── What it costs ────────────────────────────────────────────────────

    public function test_a_night_with_no_season_takes_the_base_rate(): void
    {
        $quote = $this->availability->quote($this->room, $this->date('2027-03-03'), $this->date('2027-03-05'));

        $this->assertSame(2, $quote->nights());
        $this->assertSame(20000, $quote->total()->minor);
        $this->assertSame('USD', $quote->total()->currency);
    }

    public function test_a_season_prices_the_nights_it_covers(): void
    {
        Rate::factory()->create([
            'room_type_id' => $this->room->id,
            'starts_on' => '2027-03-04',
            'ends_on' => '2027-03-04',
            'rate_minor' => 25000,
        ]);

        $quote = $this->availability->quote($this->room, $this->date('2027-03-03'), $this->date('2027-03-05'));

        $this->assertSame(
            ['2027-03-03' => 10000, '2027-03-04' => 25000],
            $quote->nightly,
        );
        $this->assertSame(35000, $quote->total()->minor);
    }

    /**
     * A season is inclusive at both ends — unlike a stay.
     *
     * A guesthouse owner typing "1 to 31 December" means the 31st. The two
     * rules genuinely differ, and this is the assertion that keeps somebody
     * from "fixing" one to match the other.
     */
    public function test_a_season_covers_its_last_day(): void
    {
        Rate::factory()->create([
            'room_type_id' => $this->room->id,
            'starts_on' => '2027-03-01',
            'ends_on' => '2027-03-04',
            'rate_minor' => 25000,
        ]);

        $quote = $this->availability->quote($this->room, $this->date('2027-03-04'), $this->date('2027-03-05'));

        $this->assertSame(['2027-03-04' => 25000], $quote->nightly);
    }

    /**
     * Where two seasons cover a night, the one starting later wins.
     *
     * A year-round rate with a new-year override inside it is the shape
     * guesthouses actually write, and the narrower season is what they
     * mean. Deterministic by ordering, not by whichever row came back
     * first — that would change silently with an index.
     */
    public function test_the_narrower_season_wins_where_two_overlap(): void
    {
        Rate::factory()->create([
            'room_type_id' => $this->room->id,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'rate_minor' => 12000,
        ]);

        Rate::factory()->create([
            'room_type_id' => $this->room->id,
            'starts_on' => '2027-03-01',
            'ends_on' => '2027-03-31',
            'rate_minor' => 30000,
        ]);

        $quote = $this->availability->quote($this->room, $this->date('2027-03-03'), $this->date('2027-03-04'));

        $this->assertSame(['2027-03-03' => 30000], $quote->nightly);
    }

    /** A season starting on the check-out date prices nothing. */
    public function test_a_season_beginning_at_check_out_changes_no_price(): void
    {
        Rate::factory()->create([
            'room_type_id' => $this->room->id,
            'starts_on' => '2027-03-05',
            'ends_on' => '2027-03-20',
            'rate_minor' => 99000,
        ]);

        $quote = $this->availability->quote($this->room, $this->date('2027-03-03'), $this->date('2027-03-05'));

        $this->assertSame(20000, $quote->total()->minor);
    }

    // ── The deposit ──────────────────────────────────────────────────────

    public function test_the_deposit_is_the_stated_percentage_of_the_total(): void
    {
        $quote = $this->availability->quote($this->room, $this->date('2027-03-03'), $this->date('2027-03-05'));

        $this->assertSame(6000, $quote->deposit(30)->minor);
    }

    /**
     * Rounded up, not down.
     *
     * 30% of 8505 cents is 2551.5. Rounding down would collect a deposit
     * half a cent under the policy printed on the page — and the balance is
     * the remainder either way, so nothing is lost by taking the number
     * that actually satisfies what was stated.
     */
    public function test_a_deposit_that_does_not_divide_evenly_rounds_up(): void
    {
        $this->room->update(['base_rate_minor' => 8505]);

        $quote = $this->availability->quote($this->room->fresh(), $this->date('2027-03-03'), $this->date('2027-03-04'));

        $this->assertSame(8505, $quote->total()->minor);
        $this->assertSame(2552, $quote->deposit(30)->minor);
    }

    // ── The snapshot ─────────────────────────────────────────────────────

    /**
     * The per-night breakdown is what gets frozen onto the stay, so a
     * season edited next week cannot move a price somebody already agreed
     * to. A bare total would freeze the number without the reasoning, and
     * the first question about a disputed invoice is which night cost what.
     */
    public function test_the_snapshot_keeps_the_nightly_breakdown(): void
    {
        Rate::factory()->create([
            'room_type_id' => $this->room->id,
            'starts_on' => '2027-03-04',
            'ends_on' => '2027-03-04',
            'rate_minor' => 25000,
        ]);

        $snapshot = $this->availability
            ->quote($this->room, $this->date('2027-03-03'), $this->date('2027-03-05'))
            ->snapshot();

        $this->assertSame('USD', $snapshot['currency']);
        $this->assertSame(['2027-03-03' => 10000, '2027-03-04' => 25000], $snapshot['nightly']);
        $this->assertSame(35000, $snapshot['total_minor']);
        $this->assertArrayHasKey('quoted_at', $snapshot);
    }
}
