<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Stays\StayAllocator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proof that the row lock is real — and here, that it is *everything*.
 *
 * A departure has a database CHECK underneath its lock, so a forgotten
 * `lockForUpdate()` still cannot oversell it. **A room type has no such
 * backstop and cannot have one**: the rule spans rows — "for every night in
 * this range, how many other stays overlap it" — and a CHECK sees one row
 * and cannot count its neighbours. See the migration
 * `create_the_stays_availability` and App\Services\Stays\StayAllocator.
 *
 * So this file is not a confirmation of the guarantee. It is the guarantee.
 * Every other stays test runs on one thread, where the arithmetic is
 * identical whether or not a lock was ever taken; delete `lockForUpdate()`
 * from the allocator and all forty-five of them still pass.
 *
 * Needs an engine with real row locking, which SQLite is not — it is
 * skipped there, loudly, and runs in the MySQL job that exists in CI for
 * exactly this.
 *
 * ## Why this file manages its own data
 *
 * It can use neither of the usual traits, for the reasons `SeatLockTest`
 * records at length: `RefreshDatabase` wraps each test in a transaction on
 * the default connection, so the second connection could not see the
 * fixture at all; and `DatabaseTruncation` empties `roles` and
 * `permissions`, which this application populates from migrations rather
 * than a seeder, breaking twenty unrelated tests later in the run.
 *
 * So it commits its fixture and removes exactly that fixture afterwards,
 * touching no other table. Cleanup does not depend on the test body
 * reaching any particular line: an open transaction still holding a row
 * lock would make the next test's writes wait, and `lock_wait_timeout`
 * defaults to a year.
 */
class StayLockTest extends TestCase
{
    /** Long enough to prove the lock blocks, short enough not to stall CI. */
    private const LOCK_TIMEOUT_SECONDS = 1;

    private ?RoomType $room = null;

    private ?Customer $customer = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Row locking needs MySQL or MariaDB. This runs in the MySQL job in CI; '
                .'SQLite has no row locks to take — and for stays the lock is the only guard there is.',
            );
        }

        if (! Schema::hasTable('room_types')) {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        }

        config(['database.connections.second' => config('database.connections.'.config('database.default'))]);
        DB::purge('second');
    }

    protected function tearDown(): void
    {
        $this->releaseQuietly('second');
        $this->releaseQuietly(null);
        $this->removeFixture();

        parent::tearDown();
    }

    /** Roll back whatever is open and drop the session; never throw. */
    private function releaseQuietly(?string $name): void
    {
        try {
            $connection = DB::connection($name);

            while ($connection->transactionLevel() > 0) {
                try {
                    $connection->rollBack();
                } catch (\Throwable) {
                    // MySQL rolls the whole transaction back itself when it
                    // resolves contention as a deadlock, and rollBack() then
                    // throws "there is no active transaction". Disconnecting
                    // releases the locks either way.
                    break;
                }
            }

            DB::disconnect($name);
        } catch (\Throwable) {
            // Never opened, or setUp skipped. Nothing to release.
        }
    }

    /**
     * Everything this file made, in dependency order.
     *
     * A leaked published property would be visible to every later test that
     * counts what is on sale, so this runs after the sessions are released,
     * on a connection that is certainly free.
     */
    private function removeFixture(): void
    {
        if ($this->room === null) {
            return;
        }

        try {
            $property = Property::find($this->room->property_id);

            Stay::where('room_type_id', $this->room->getKey())->delete();
            RoomType::whereKey($this->room->getKey())->delete();

            if ($property !== null) {
                $partnerId = $property->partner_id;
                Property::whereKey($property->getKey())->delete();
                Partner::whereKey($partnerId)->delete();
            }

            if ($this->customer !== null) {
                Customer::whereKey($this->customer->getKey())->delete();
            }
        } catch (\Throwable) {
            // Nothing useful to do, and throwing would replace a real test
            // result with a cleanup error.
        }

        $this->room = null;
        $this->customer = null;
    }

    /**
     * `unprepared()`, not `statement()`: a session variable set through the
     * prepared-statement protocol is not worth the doubt when the whole
     * point is that the next query gives up quickly.
     */
    private function impatient(ConnectionInterface $connection): ConnectionInterface
    {
        $connection->unprepared('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_TIMEOUT_SECONDS);

        return $connection;
    }

    /** One room, one of it. The last room in the guesthouse. */
    private function theLastRoom(): RoomType
    {
        $property = Property::factory()->create(['currency' => 'USD', 'min_nights' => 1]);

        $this->customer = Customer::factory()->create();

        return $this->room = RoomType::factory()->create([
            'property_id' => $property->getKey(),
            'quantity' => 1,
            'base_rate_minor' => 10000,
        ]);
    }

    private function request(): Stay
    {
        return Stay::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'room_type_id' => $this->room->getKey(),
            'property_id' => $this->room->property_id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
            'status' => Stay::REQUESTED,
        ]);
    }

    public function test_a_locked_room_type_row_blocks_a_second_reader(): void
    {
        $room = $this->theLastRoom();

        DB::beginTransaction();
        RoomType::whereKey($room->getKey())->lockForUpdate()->firstOrFail();

        $second = $this->impatient(DB::connection('second'));
        $second->beginTransaction();

        $blocked = false;

        try {
            $second->table('room_types')->where('id', $room->getKey())->lockForUpdate()->first();
        } catch (QueryException) {
            // A lock-wait timeout or a deadlock. Either is MySQL saying the
            // row is spoken for, which is the whole assertion.
            $blocked = true;
        }

        $this->assertTrue($blocked, 'A second session read the room type row while it was locked for update.');
    }

    /**
     * The control.
     *
     * Without it, the test above would pass just as happily if the second
     * connection were broken, or if every query on it timed out for an
     * unrelated reason. Same connection, same timeout, no lock held: it
     * must read the row.
     */
    public function test_the_second_connection_reads_the_row_when_nothing_holds_it(): void
    {
        $room = $this->theLastRoom();

        $second = $this->impatient(DB::connection('second'));
        $second->beginTransaction();

        $row = $second->table('room_types')->where('id', $room->getKey())->lockForUpdate()->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->quantity);
    }

    /**
     * The one that matters: **the allocator's first act is to take the lock.**
     *
     * The two tests above prove MySQL locks a row. This proves the code
     * path a real booking takes is inside that contention — a different
     * claim, and the only one that stops two families arriving at one door.
     *
     * ## Why this asserts on *which* statement blocked
     *
     * The obvious version of this test — "another session holds the room,
     * so the allocator must throw" — passes with `lockForUpdate()` deleted,
     * and it took planting the defect to find that out. The reason is a
     * genuine InnoDB behaviour rather than a quirk of the test:
     * `stays.room_type_id` is a foreign key, so **writing a stay takes a
     * shared lock on its parent `room_types` row**, and that shared lock is
     * refused while another session holds the row exclusively. The
     * allocator blocked, threw, and looked correct — on a statement that
     * had nothing to do with the guarantee.
     *
     * That incidental blocking is not the invariant, because it does not
     * scale to the case that matters. Two allocators racing with no lock
     * would each take only a *shared* FK lock on the parent, shared locks
     * are compatible with each other, and both would write. The room is
     * double-booked and nothing has thrown.
     *
     * So the assertion is on the failing statement: it must be the
     * `select … for update` against `room_types`, taken before the
     * allocator reads availability or writes anything. Delete
     * `lockForUpdate()` from `StayAllocator::lock()` and the failure moves
     * to the `stays` update — this test fails, and every other stays test
     * still passes.
     */
    public function test_the_allocator_takes_the_room_lock_before_anything_else(): void
    {
        $room = $this->theLastRoom();
        $stay = $this->request();

        // Somebody else is mid-booking on this room, holding its row.
        $second = DB::connection('second');
        $second->beginTransaction();
        $second->table('room_types')->where('id', $room->getKey())->lockForUpdate()->first();

        $this->impatient(DB::connection());

        $failedOn = null;

        try {
            app(StayAllocator::class)->hold($stay);
        } catch (QueryException $refusal) {
            $failedOn = strtolower((string) ($refusal->getSql() ?? ''));
        }

        $this->assertNotNull(
            $failedOn,
            'The allocator took the dates while another session held the room type row — '
            .'the lock is the only guard there is here, and it did not hold.',
        );

        $this->assertStringContainsString(
            'room_types',
            $failedOn,
            'The allocator blocked on the wrong statement. It must contend for the room type row '
            ."itself, not reach a later write and be stopped there by a foreign key. Blocked on: {$failedOn}",
        );

        $this->assertStringContainsString(
            'for update',
            $failedOn,
            "The statement that blocked was not a row lock. Blocked on: {$failedOn}",
        );

        $this->assertSame(
            Stay::REQUESTED,
            Stay::whereKey($stay->getKey())->value('status'),
            'A refused hold must leave the request exactly as it was.',
        );
    }

    /**
     * And once the other session lets go, the same stay holds normally.
     *
     * The mirror of the test above: without it, a permanently broken
     * allocator would look like a correctly cautious one.
     */
    public function test_the_allocator_takes_the_dates_once_the_other_session_commits(): void
    {
        $room = $this->theLastRoom();
        $stay = $this->request();

        $second = DB::connection('second');
        $second->beginTransaction();
        $second->table('room_types')->where('id', $room->getKey())->lockForUpdate()->first();
        $second->rollBack();

        app(StayAllocator::class)->hold($stay);

        $this->assertSame(Stay::HELD, Stay::whereKey($stay->getKey())->value('status'));
    }
}
