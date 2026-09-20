<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\Package;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 * ## Why this file manages its own data
 *
 * It can use neither of the usual traits. `RefreshDatabase` wraps each test
 * in a transaction on the default connection, so the second connection could
 * not see the fixture at all and the test would fail for a reason having
 * nothing to do with locking.
 *
 * `DatabaseTruncation` was the first answer, and it broke twenty other tests
 * in the MySQL job: it empties *every* table, including `roles` and
 * `permissions`, which this application populates from migrations rather
 * than a seeder. From that point on in the run, every test that assigned
 * "Super Admin" failed with `RoleDoesNotExist` — a failure twenty files
 * away from its cause, which is the worst kind to debug. Nothing in this
 * file's own results hinted at it; both tests here passed.
 *
 * So it commits its fixture and removes exactly that fixture afterwards,
 * touching no other table. Two rows in, two rows out.
 *
 * Cleanup deliberately does not depend on the test body reaching any
 * particular line, nor on one step succeeding for the next to be attempted.
 * An open transaction still holding a row lock on `departures` would make
 * the next test's writes wait, and `lock_wait_timeout` defaults to a year —
 * so both sessions are disconnected at the end regardless of what state
 * their transactions were left in.
 */
class SeatLockTest extends TestCase
{
    /** Long enough to prove the lock blocks, short enough not to stall CI. */
    private const LOCK_TIMEOUT_SECONDS = 1;

    private ?Departure $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Row locking needs MySQL or MariaDB. This runs in the MySQL job in CI; '
                .'SQLite has no row locks to take.',
            );
        }

        // The suite's RefreshDatabase tests migrate the database once, before
        // the first of them runs. Running this file on its own would not, so
        // check rather than assume.
        if (! Schema::hasTable('departures')) {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        }

        // A clone of the default connection, so the two transactions below
        // are genuinely two sessions rather than the same one twice.
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
                    // below releases the locks either way, which is the only
                    // thing that actually matters.
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
     * Two rows out.
     *
     * A leaked departure would be published, upcoming and visible to every
     * later test that counts what is on sale — so this runs after the
     * sessions are released, on a connection that is certainly free.
     */
    private function removeFixture(): void
    {
        if ($this->fixture === null) {
            return;
        }

        try {
            Departure::whereKey($this->fixture->getKey())->delete();
            Package::whereKey($this->fixture->package_id)->delete();
        } catch (\Throwable) {
            // Nothing useful to do here, and throwing would replace a real
            // test result with a cleanup error.
        }

        $this->fixture = null;
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

    private function departure(): Departure
    {
        return $this->fixture = Departure::factory()->withSeats(1)->create();
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
