<?php

namespace Tests\Feature;

use App\Models\Departure;
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
 * exactly this. A concurrency test that only ever runs on SQLite reports
 * green while proving nothing.
 *
 * DatabaseTruncation rather than RefreshDatabase: RefreshDatabase wraps each
 * test in a transaction on the default connection, so a second connection
 * could not see the fixture at all and the test would fail for a reason
 * having nothing to do with locking.
 */
class SeatLockTest extends TestCase
{
    use DatabaseTruncation;

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

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(1)->create();
    }

    public function test_a_locked_departure_row_blocks_a_second_reader(): void
    {
        $departure = $this->departure();

        DB::beginTransaction();
        Departure::whereKey($departure->getKey())->lockForUpdate()->firstOrFail();

        $second = DB::connection('second');
        // Without this the second connection waits fifty seconds for a lock
        // it is never going to get, and the test times out instead of
        // failing usefully.
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $second->beginTransaction();

        $blocked = false;

        try {
            $second->table('departures')->where('id', $departure->getKey())->lockForUpdate()->first();
        } catch (QueryException) {
            $blocked = true;
        } finally {
            $second->rollBack();
            DB::rollBack();
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

        $second = DB::connection('second');
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $second->beginTransaction();

        $row = $second->table('departures')->where('id', $departure->getKey())->lockForUpdate()->first();

        $second->rollBack();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->capacity_total);
    }
}
