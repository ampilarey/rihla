<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A departure may never hold and confirm more seats than it has.
 *
 * The plan is explicit (§5.2): overbooking must be prevented by a
 * **DB-level constraint, not just an app check**. The application already
 * takes a `SELECT … FOR UPDATE` row lock on the departure before it touches
 * the counters (App\Services\Booking\SeatAllocator), and that lock is what
 * makes two simultaneous bookings take turns. This constraint is the
 * backstop underneath it: a console command, a tinker session, a future
 * import script or a code path that forgets the lock cannot leave the row in
 * a state where more people are booked than can fly.
 *
 * `capacity_total = 0` means nobody has entered a capacity, and this
 * constraint therefore refuses every seat on such a departure. That is
 * deliberate: every departure backfilled from `trips` starts at zero, and a
 * departure whose capacity nobody has recorded is one nobody can oversell.
 * The allocator says so in as many words rather than letting the database
 * produce an integrity error.
 *
 * **[R-5] — the production MySQL version is still unknown.** MySQL enforces
 * CHECK constraints from 8.0.16 and MariaDB from 10.2.1; older MySQL parses
 * the clause and silently ignores it, which is the worst possible outcome
 * for a guarantee. So the version is read and the constraint is added only
 * where it will actually be enforced. On SQLite — the test and local
 * database — the same rule is installed as a pair of triggers, because
 * SQLite cannot add a CHECK to an existing table and a rule that exists on
 * one engine only is a rule the test suite cannot exercise.
 *
 * Where the constraint cannot be installed, the row lock still carries the
 * guarantee. Nothing is weakened; the backstop is simply absent, and
 * `php artisan preflight` can say so.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'departures_capacity_not_exceeded';

    private const RULE = 'capacity_held + capacity_confirmed <= capacity_total';

    public function up(): void
    {
        $connection = Schema::getConnection();

        $this->repairAnyRowThatAlreadyViolatesIt();

        match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $this->addCheck($connection, $this->checksAreEnforced($connection)),
            'pgsql' => $this->addCheck($connection, true),
            'sqlite' => $this->addTriggers($connection),
            // Not a silent skip: an engine nobody has thought about must
            // announce itself rather than quietly dropping the backstop.
            default => throw new RuntimeException(
                'No capacity constraint is defined for the "'.$connection->getDriverName().'" driver. '
                .'Add one before running this migration against it.',
            ),
        };
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        match ($connection->getDriverName()) {
            'sqlite' => $this->dropTriggers($connection),
            'mysql', 'mariadb', 'pgsql' => $this->dropCheck($connection),
            default => null,
        };
    }

    /**
     * An already-oversold row would make the ALTER fail and the deploy stop.
     *
     * Raising the total to what has actually been taken records the truth —
     * those people are booked — rather than deleting a booking to satisfy a
     * constraint. In practice this touches nothing: every departure
     * backfilled from `trips` has all three counters at zero.
     */
    private function repairAnyRowThatAlreadyViolatesIt(): void
    {
        if (! Schema::hasTable('departures')) {
            return;
        }

        DB::table('departures')
            ->whereRaw('capacity_held + capacity_confirmed > capacity_total')
            ->update(['capacity_total' => DB::raw('capacity_held + capacity_confirmed')]);
    }

    private function addCheck(Connection $connection, bool $enforced): void
    {
        if (! $enforced) {
            return;
        }

        $connection->statement(sprintf(
            'ALTER TABLE departures ADD CONSTRAINT %s CHECK (%s)',
            self::CONSTRAINT,
            self::RULE,
        ));
    }

    private function dropCheck(Connection $connection): void
    {
        try {
            $connection->statement('ALTER TABLE departures DROP CONSTRAINT '.self::CONSTRAINT);
        } catch (Throwable) {
            // It was never added — an older MySQL, per the docblock. Rolling
            // back must not fail because the thing being removed was never
            // installed in the first place.
        }
    }

    /** MySQL 8.0.16+ and MariaDB 10.2.1+ enforce CHECK; anything older ignores it. */
    private function checksAreEnforced(Connection $connection): bool
    {
        /** @var object{version: string}|null $row */
        $row = $connection->selectOne('select version() as version');
        $version = $row->version ?? '';

        if (stripos($version, 'mariadb') !== false) {
            return version_compare($this->numericPrefix($version), '10.2.1', '>=');
        }

        return version_compare($this->numericPrefix($version), '8.0.16', '>=');
    }

    /** "8.0.36-0ubuntu0.22.04.1" → "8.0.36". */
    private function numericPrefix(string $version): string
    {
        preg_match('/^\d+(\.\d+)*/', $version, $matches);

        return $matches[0] ?? '0';
    }

    /**
     * SQLite's equivalent.
     *
     * RAISE(ABORT) rolls the statement back and surfaces as a
     * QueryException, which is what the MySQL constraint does too — so the
     * concurrency test asserts the same behaviour on both engines instead of
     * passing vacuously on the one developers actually run.
     */
    private function addTriggers(Connection $connection): void
    {
        foreach (['insert', 'update'] as $event) {
            $connection->statement(sprintf(
                'CREATE TRIGGER %s_%s BEFORE %s ON departures FOR EACH ROW '
                .'WHEN NEW.capacity_held + NEW.capacity_confirmed > NEW.capacity_total '
                ."BEGIN SELECT RAISE(ABORT, '%s'); END",
                self::CONSTRAINT,
                $event,
                strtoupper($event),
                self::CONSTRAINT,
            ));
        }
    }

    private function dropTriggers(Connection $connection): void
    {
        foreach (['insert', 'update'] as $event) {
            $connection->statement(sprintf('DROP TRIGGER IF EXISTS %s_%s', self::CONSTRAINT, $event));
        }
    }
};
