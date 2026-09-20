<?php

namespace Tests\Feature;

use App\Models\Departure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proof that the row lock is real.
 *
 * [R-2]: the capacity guarantee rests on `SELECT … FOR UPDATE`. Every other
 * test in this suite exercises the allocator from one thread, where the
 * arithmetic would look identical whether or not a lock was ever taken — so
 * none of them can tell a working guarantee from a decorative one.
 *
 * This one can. Two connections, two transactions: the first takes the lock
 * on the departure row, the second asks for the same lock with a one-second
 * timeout and must be refused. If `lockForUpdate()` were dropped from the
 * allocator, or silently ignored by the engine, the second connection would
 * sail through and this test would fail.
 *
 * It needs an engine with real row locking, which SQLite is not — it is
 * skipped there, loudly, and runs in the MySQL job that exists in CI for
 * exactly this.
 *
 * DatabaseTruncation rather than RefreshDatabase: RefreshDatabase wraps each
 * test in a transaction on the default connection, so a second connection
 * could not see the fixture at all and the test would fail for a reason
 * having nothing to do with locking.
 *
 * ## Why tearDown is so careful
 *
 * The first version of this test hung CI for sixteen minutes — the whole run
 * normally takes two. It rolled both transactions back in a `finally`, with
 * the second connection's rollback first. When MySQL resolves the contention
 * as a deadlock rather than a lock-wait timeout it has *already* rolled that
 * transaction back, so `rollBack()` throws "there is no active transaction",
 * the second statement in the `finally` never runs, and the default
 * connection keeps its row lock. The next test's `TRUNCATE departures` then
 * waits on a metadata lock — and `lock_wait_timeout` defaults to a year.
 *
 * So cleanup does not depend on the test body reaching any particular line,
 * does not depend on one rollback succeeding for the next to be attempted,
 * and finishes by disconnecting both sessions, which releases every lock
 * whatever state the transactions were left in.
 */
class SeatLockTest extends TestCase
{
    use DatabaseTruncation;

    /** Long enough to prove the lock blocks, short enough not to stall CI. */
    private const LOCK_TIMEOUT_SECONDS = 1;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Row locking needs MySQL or MariaDB. This runs in the MySQL job in CI; '
                .'SQLite has no row locks to take.',
            );
        }

        // A clone of the default connection, so the two transactions below
        // are genuinely two sessions rather than the same one twice.
        config(['database.connections.second' => config('database.connections.'.config('database.default'))]);
        DB::purge('second');
    }

    protected function tearDown(): void
    {
        // Unconditional, independent, and quiet. See the class docblock: an
        // open transaction surviving this method is what hung CI.
        $this->releaseQuietly('second');
        $this->releaseQuietly(null);

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
                    // MySQL may have rolled it back already. Disconnecting
                    // below releases the locks regardless, which is the only
                    // thing that actually matters here.
                    break;
                }
            }

            DB::disconnect($name);
        } catch (\Throwable) {
            // A connection that was never opened, or a config entry that was
            // never registered because setUp skipped. Nothing to release.
        }
    }

    /**
     * `unprepared()`, not `statement()`: a session variable set through the
     * prepared-statement protocol is not worth the doubt when the whole
     * point is that this query gives up quickly.
     */
    private function impatient(ConnectionInterface $connection): ConnectionInterface
    {
        $connection->unprepared('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_TIMEOUT_SECONDS);

        return $connection;
    }

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(1)->create();
    }

    public function test_a_locked_departure_row_blocks_a_second_reader(): void
    {
        $departure = $this->departure();

        DB::beginTransaction();
        Departure::whereKey($departure->getKey())->lockForUpdate()->firstOrFail();

        $second = $this->impatient(DB::connection('second'));
        $second->beginTransaction();

        $blocked = false;

        try {
            $second->table('departures')->where('id', $departure->getKey())->lockForUpdate()->first();
        } catch (QueryException) {
            // A lock-wait timeout or a deadlock. Either is MySQL saying the
            // row is spoken for, which is the whole assertion.
            $blocked = true;
        }

        $this->assertTrue($blocked, 'A second session read the departure row while it was locked for update.');
    }

    /**
     * The control.
     *
     * Without it, the test above would pass just as happily if the second
     * connection were broken, or if every query on it timed out for some
     * unrelated reason. Same connection, same timeout, no lock held: it must
     * read the row.
     */
    public function test_the_second_connection_reads_the_row_when_nothing_holds_it(): void
    {
        $departure = $this->departure();

        $second = $this->impatient(DB::connection('second'));
        $second->beginTransaction();

        $row = $second->table('departures')->where('id', $departure->getKey())->lockForUpdate()->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->capacity_total);
    }
}
