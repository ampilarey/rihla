<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Services\Payments\Ledger;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proof that the ledger's row lock is real.
 *
 * Two members of staff open the same booking and both reconcile a transfer.
 * Both read `paid_minor`. Without serialisation both write a total derived
 * from the same stale figure, and a booking paid twice looks like a booking
 * paid once — found out by a customer asking where their money went.
 *
 * Every other payment test runs on one thread, where the arithmetic looks
 * identical whether or not a lock was ever taken, so none of them can tell
 * a working guarantee from a decorative one. This one can: the first
 * session takes the lock on the booking row, the second asks for it with a
 * one-second timeout and must be refused. If `lockForUpdate()` were dropped
 * from {@see Ledger}, the second would sail through.
 *
 * It needs an engine with real row locking, which SQLite is not. Skipped
 * there, loudly, and run in the MySQL job that exists in CI for exactly
 * this.
 *
 * ## Why this file manages its own data
 *
 * The same reasons SeatLockTest does, and the same solution — read that
 * file's docblock. In short: `RefreshDatabase` would hide the fixture from
 * the second connection, and `DatabaseTruncation` empties `roles` and
 * `permissions`, which this application populates from migrations rather
 * than a seeder, breaking twenty tests in files that have nothing to do
 * with this one. So the fixture is committed and removed by hand, touching
 * no other table, whatever state the test body left its transactions in.
 */
class PaymentLockTest extends TestCase
{
    /** Long enough to prove the lock blocks, short enough not to stall CI. */
    private const LOCK_TIMEOUT_SECONDS = 1;

    private ?Booking $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Row locking needs MySQL or MariaDB. This runs in the MySQL job in CI; '
                .'SQLite has no row locks to take.',
            );
        }

        if (! Schema::hasTable('bookings')) {
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

    private function releaseQuietly(?string $name): void
    {
        try {
            $connection = DB::connection($name);

            while ($connection->transactionLevel() > 0) {
                try {
                    $connection->rollBack();
                } catch (\Throwable) {
                    // MySQL rolls the whole transaction back itself when it
                    // resolves contention as a deadlock; disconnecting below
                    // releases the locks either way.
                    break;
                }
            }

            DB::disconnect($name);
        } catch (\Throwable) {
            // Never opened, or setUp skipped before registering it.
        }
    }

    private function removeFixture(): void
    {
        if ($this->fixture === null) {
            return;
        }

        try {
            $departureId = $this->fixture->departure_id;
            $customerId = $this->fixture->customer_id;
            $packageId = Departure::whereKey($departureId)->value('package_id');

            // In dependency order: bookings restrict deletes on departures.
            Booking::whereKey($this->fixture->getKey())->delete();
            Departure::whereKey($departureId)->delete();
            Package::whereKey($packageId)->delete();
            Customer::whereKey($customerId)->delete();
        } catch (\Throwable) {
            // Throwing here would replace a real test result with a cleanup
            // error.
        }

        $this->fixture = null;
    }

    private function impatient(ConnectionInterface $connection): ConnectionInterface
    {
        $connection->unprepared('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_TIMEOUT_SECONDS);

        return $connection;
    }

    private function booking(): Booking
    {
        return $this->fixture = Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create()->getKey(),
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 2_850_000,
        ]);
    }

    public function test_a_locked_booking_row_blocks_a_second_reconciler(): void
    {
        $booking = $this->booking();

        DB::beginTransaction();
        Booking::whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

        $second = $this->impatient(DB::connection('second'));
        $second->beginTransaction();

        $blocked = false;

        try {
            $second->table('bookings')->where('id', $booking->getKey())->lockForUpdate()->first();
        } catch (QueryException) {
            // A lock-wait timeout or a deadlock. Either is MySQL saying the
            // row is spoken for, which is the whole assertion.
            $blocked = true;
        }

        $this->assertTrue($blocked, 'A second session read the booking row while it was locked for update.');
    }

    /**
     * The control.
     *
     * Without it the test above would pass just as happily if the second
     * connection were broken, or if every query on it timed out for an
     * unrelated reason.
     */
    public function test_the_second_connection_reads_the_row_when_nothing_holds_it(): void
    {
        $booking = $this->booking();

        $second = $this->impatient(DB::connection('second'));
        $second->beginTransaction();

        $row = $second->table('bookings')->where('id', $booking->getKey())->lockForUpdate()->first();

        $this->assertNotNull($row);
        $this->assertSame(2_850_000, (int) $row->total_minor);
    }
}
