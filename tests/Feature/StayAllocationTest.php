<?php

namespace Tests\Feature;

use App\Exceptions\IllegalStayTransition;
use App\Exceptions\RoomNotAvailable;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Stays\StayAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taking the dates — §15.4 (Phase 9.2).
 *
 * `StayAvailabilityTest` proves the arithmetic; this proves the thing that
 * acts on it. Neither can prove the part that actually matters under load:
 * every test here runs on one thread, where the result would be identical
 * with the row lock deleted. `StayLockTest` is the one that can tell those
 * apart, and it runs on MySQL only.
 */
class StayAllocationTest extends TestCase
{
    use RefreshDatabase;

    private StayAllocator $allocator;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocator = app(StayAllocator::class);

        $property = Property::factory()->create(['currency' => 'USD', 'min_nights' => 1]);

        $this->room = RoomType::factory()->create([
            'property_id' => $property->id,
            'quantity' => 1,
            'base_rate_minor' => 10000,
        ]);
    }

    private function stay(array $attributes = []): Stay
    {
        return Stay::factory()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
            ...$attributes,
        ]);
    }

    // ── Holding ──────────────────────────────────────────────────────────

    public function test_holding_takes_the_dates_and_starts_the_clock(): void
    {
        $stay = $this->allocator->hold($this->stay());

        $this->assertSame(Stay::HELD, $stay->status);
        $this->assertNotNull($stay->partner_confirmed_at);
        $this->assertNotNull($stay->expires_at);
        $this->assertTrue($stay->expires_at->greaterThan(now()));
    }

    /** The whole point: the second ask for the last room is refused. */
    public function test_the_second_hold_on_the_last_room_is_refused(): void
    {
        $this->allocator->hold($this->stay());

        $this->expectException(RoomNotAvailable::class);

        $this->allocator->hold($this->stay());
    }

    public function test_holding_an_already_held_stay_changes_nothing(): void
    {
        $stay = $this->allocator->hold($this->stay());
        $expiry = $stay->expires_at;

        $again = $this->allocator->hold($stay);

        $this->assertSame(Stay::HELD, $again->status);
        $this->assertTrue($expiry->equalTo($again->expires_at));
        $this->assertSame(1, Stay::where('status', Stay::HELD)->count());
    }

    /**
     * A refused hold leaves the stay exactly where it was.
     *
     * A request that silently became something else on a failure would
     * leave a customer's ask in a status nobody chose — and, worse, one the
     * partner can no longer act on.
     */
    public function test_a_refused_hold_leaves_the_request_untouched(): void
    {
        $this->allocator->hold($this->stay());
        $second = $this->stay();

        try {
            $this->allocator->hold($second);
            $this->fail('The second hold should have been refused.');
        } catch (RoomNotAvailable) {
            // expected
        }

        $this->assertSame(Stay::REQUESTED, $second->fresh()->status);
        $this->assertNull($second->fresh()->expires_at);
    }

    // ── Confirming ───────────────────────────────────────────────────────

    public function test_confirming_a_hold_keeps_the_dates_and_stops_the_clock(): void
    {
        $stay = $this->allocator->confirm($this->allocator->hold($this->stay()));

        $this->assertSame(Stay::CONFIRMED, $stay->status);
        $this->assertNull($stay->expires_at);
        $this->assertNotNull($stay->confirmed_at);
    }

    /** A payment callback may arrive twice; the second must be harmless. */
    public function test_confirming_twice_is_harmless(): void
    {
        $stay = $this->allocator->confirm($this->allocator->hold($this->stay()));
        $confirmedAt = $stay->confirmed_at;

        $again = $this->allocator->confirm($stay);

        $this->assertSame(Stay::CONFIRMED, $again->status);
        $this->assertTrue($confirmedAt->equalTo($again->confirmed_at));
    }

    /**
     * The hold lapsed at 09:00 and the deposit landed at 09:01.
     *
     * The dates went back and somebody else took them, so this throws —
     * which is the truth of the situation, and far better found here, where
     * the money can be refunded, than at a guesthouse door.
     */
    public function test_a_deposit_arriving_after_the_dates_were_retaken_is_refused(): void
    {
        $lapsed = $this->stay(['status' => Stay::HELD, 'expires_at' => now()->subHour()]);

        // Somebody else asks, which expires the lapsed hold and takes the
        // dates — exactly the path a real second customer would follow.
        $this->allocator->hold($this->stay());

        $this->assertSame(Stay::EXPIRED, $lapsed->fresh()->status);

        $this->expectException(RoomNotAvailable::class);

        $this->allocator->confirm($lapsed->fresh());
    }

    // ── Releasing ────────────────────────────────────────────────────────

    public function test_releasing_a_hold_puts_the_dates_back(): void
    {
        $stay = $this->allocator->hold($this->stay());

        $this->allocator->release($stay);

        $this->assertSame(Stay::CANCELLED, $stay->fresh()->status);

        // And the room is genuinely bookable again, not merely marked so.
        $this->assertSame(Stay::HELD, $this->allocator->hold($this->stay())->status);
    }

    /**
     * Declining is not cancelling, and the record has to say which.
     *
     * "The guest changed their mind" and "the guesthouse said no" lead to
     * different conversations and different numbers in §8.4's reporting.
     */
    public function test_declining_is_recorded_as_its_own_ending(): void
    {
        $stay = $this->allocator->decline($this->stay(), 'The partner is full that week.');

        $this->assertSame(Stay::DECLINED, $stay->fresh()->status);
        $this->assertSame('The partner is full that week.', $stay->fresh()->cancellation_reason);
    }

    public function test_releasing_something_already_finished_changes_nothing(): void
    {
        $stay = $this->allocator->decline($this->stay());

        $this->allocator->release($stay);

        $this->assertSame(Stay::DECLINED, $stay->fresh()->status);
    }

    // ── Expiry, which is never left to cron ──────────────────────────────

    /**
     * The mechanism, not the command.
     *
     * This runs on cPanel shared hosting. If expiry depended on a scheduler
     * somebody may not have configured, a guesthouse would read as full
     * while it was empty — for ever, with nothing failing. So a lapsed hold
     * is reclaimed inside the lock the *next* hold takes, and the command
     * is only a tidy-up for rooms nobody is asking about.
     */
    public function test_a_lapsed_hold_is_reclaimed_by_the_next_request(): void
    {
        $lapsed = $this->stay(['status' => Stay::HELD, 'expires_at' => now()->subMinute()]);

        $next = $this->allocator->hold($this->stay());

        $this->assertSame(Stay::EXPIRED, $lapsed->fresh()->status);
        $this->assertSame(Stay::HELD, $next->status);
    }

    /** A hold still inside its window is not touched. */
    public function test_a_live_hold_is_not_reclaimed(): void
    {
        $live = $this->stay(['status' => Stay::HELD, 'expires_at' => now()->addHour()]);

        try {
            $this->allocator->hold($this->stay());
        } catch (RoomNotAvailable) {
            // expected — the live hold still has the room
        }

        $this->assertSame(Stay::HELD, $live->fresh()->status);
    }

    public function test_reclaim_reports_how_many_it_expired(): void
    {
        $this->room->update(['quantity' => 3]);

        $this->stay(['status' => Stay::HELD, 'expires_at' => now()->subMinute()]);
        $this->stay(['status' => Stay::HELD, 'expires_at' => now()->subMinute()]);
        $this->stay(['status' => Stay::HELD, 'expires_at' => now()->addHour()]);

        $this->assertSame(2, $this->allocator->reclaim($this->room->fresh()));
    }

    /** A confirmed stay has no clock and must never be swept up by one. */
    public function test_a_confirmed_stay_is_never_expired(): void
    {
        $confirmed = $this->allocator->confirm($this->allocator->hold($this->stay()));

        $this->allocator->reclaim($this->room->fresh());

        $this->assertSame(Stay::CONFIRMED, $confirmed->fresh()->status);
    }

    // ── The status machine ───────────────────────────────────────────────

    public function test_an_expired_stay_cannot_be_revived(): void
    {
        $stay = $this->stay(['status' => Stay::EXPIRED]);

        $this->expectException(IllegalStayTransition::class);

        $stay->transitionTo(Stay::HELD);
    }

    public function test_the_refusal_says_what_was_allowed_instead(): void
    {
        $stay = $this->stay(['status' => Stay::CONFIRMED]);

        try {
            $stay->transitionTo(Stay::REQUESTED);
            $this->fail('A confirmed stay should not become a request again.');
        } catch (IllegalStayTransition $refusal) {
            $this->assertStringContainsString('checked_in', $refusal->getMessage());
        }
    }

    public function test_every_status_has_a_transition_list(): void
    {
        foreach (Stay::STATUSES as $status) {
            $this->assertArrayHasKey($status, Stay::TRANSITIONS, "No transitions defined for {$status}.");
        }

        // And nothing points at a status that does not exist.
        foreach (Stay::TRANSITIONS as $from => $targets) {
            $this->assertContains($from, Stay::STATUSES);

            foreach ($targets as $target) {
                $this->assertContains($target, Stay::STATUSES, "{$from} points at unknown status {$target}.");
            }
        }
    }

    // ── References ───────────────────────────────────────────────────────

    public function test_a_stay_gets_a_reference_distinct_from_a_booking(): void
    {
        $stay = $this->stay();

        $this->assertNotNull($stay->fresh()->reference);
        $this->assertStringStartsWith('RIH-S-', $stay->fresh()->reference);
    }

    public function test_two_stays_made_together_get_different_references(): void
    {
        $this->room->update(['quantity' => 2]);

        $first = $this->stay()->fresh();
        $second = $this->stay()->fresh();

        $this->assertNotSame($first->reference, $second->reference);
    }
}
