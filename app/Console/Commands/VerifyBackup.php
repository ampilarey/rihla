<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Check that the newest backup is something you could actually restore.
 *
 * `docs/PRODUCTION_PROMOTION.md` says a backup nobody has restored is a hope
 * rather than a backup, and then leaves that as homework. This is the part of
 * that homework a machine can do.
 *
 * The failure it exists to catch is a truncated dump. `mysqldump | gzip` will
 * happily produce a file when the disk fills or the connection drops
 * mid-table: it looks right, it has a plausible size, and it restores into a
 * database that is missing the last few tables. Nobody finds out until the
 * day they need it.
 *
 * What it cannot do on shared hosting is create a scratch MySQL database to
 * restore into — that needs cPanel, not the database user. So for MySQL it
 * verifies everything short of that and prints the command for the part a
 * person must do.
 */
class VerifyBackup extends Command
{
    protected $signature = 'rihla:backup:verify
                            {file? : A specific backup to check (default: the newest)}
                            {--path= : Where backups live (default storage/app/backups)}
                            {--connection= : The live connection to compare against (default: the app default)}';

    protected $description = 'Check the newest backup is complete and restorable';

    /**
     * Tables whose contents are allowed to differ from the live database.
     *
     * Sessions, cache and queues churn between the dump and the check, so a
     * backup that has none of them is not damaged. `migrations` is not in this
     * list on purpose: restoring without it leaves a database Laravel would
     * try to migrate from scratch.
     */
    private const VOLATILE = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    public function handle(): int
    {
        $directory = $this->option('path') ?: storage_path('app/backups');

        $backup = $this->argument('file') ?: $this->newestIn($directory);

        if ($backup === null) {
            $this->error("No backup found in {$directory}. Run rihla:backup first.");

            return self::FAILURE;
        }

        if (! File::exists($backup)) {
            $this->error("No such backup: {$backup}");

            return self::FAILURE;
        }

        $this->line('Checking '.basename($backup).' ('.number_format(File::size($backup) / 1024).' KB)');

        return str_ends_with($backup, '.gz')
            ? $this->verifyMysqlDump($backup)
            : $this->verifySqlite($backup);
    }

    private function newestIn(string $directory): ?string
    {
        if (! File::isDirectory($directory)) {
            return null;
        }

        $files = collect(File::files($directory))
            ->filter(fn ($file) => str_starts_with($file->getFilename(), 'rihla-'))
            ->sortByDesc(fn ($file) => $file->getMTime());

        return $files->isEmpty() ? null : $files->first()->getPathname();
    }

    /**
     * SQLite can be checked completely: open the file and ask it.
     */
    private function verifySqlite(string $backup): int
    {
        config(['database.connections.backup_verify' => [
            'driver' => 'sqlite',
            'database' => $backup,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge('backup_verify');

        try {
            $integrity = DB::connection('backup_verify')->select('PRAGMA integrity_check');
        } catch (\Throwable $e) {
            $this->error('The backup will not open: '.$e->getMessage());

            return self::FAILURE;
        }

        $result = $integrity[0]->integrity_check ?? 'unknown';

        if ($result !== 'ok') {
            $this->error("SQLite reports the file is damaged: {$result}");

            return self::FAILURE;
        }

        $this->line('  integrity_check: ok');

        return $this->compareTables(
            fn (string $table) => DB::connection('backup_verify')->table($table)->count(),
        );
    }

    /**
     * A gzipped dump can be checked short of restoring it: that the gzip is
     * whole, that mysqldump wrote its completion marker, and that every table
     * the live schema has is present in the file.
     */
    private function verifyMysqlDump(string $backup): int
    {
        $gzip = new Process(['gzip', '-t', $backup]);
        $gzip->run();

        if (! $gzip->isSuccessful()) {
            $this->error('The gzip is damaged or truncated: '.trim($gzip->getErrorOutput()));

            return self::FAILURE;
        }

        $this->line('  gzip integrity: ok');

        $handle = gzopen($backup, 'rb');

        if ($handle === false) {
            $this->error('The backup will not open.');

            return self::FAILURE;
        }

        $tables = [];
        $tail = '';

        while (! gzeof($handle)) {
            $chunk = gzread($handle, 262144);

            if ($chunk === false) {
                gzclose($handle);
                $this->error('The backup stopped being readable part way through.');

                return self::FAILURE;
            }

            // The marker below can land across a chunk boundary, so carry the
            // end of the previous chunk forward rather than replacing it.
            $tail = substr($tail.$chunk, -1024);

            if (preg_match_all('/CREATE TABLE `([^`]+)`/', $chunk, $matches)) {
                $tables = array_merge($tables, $matches[1]);
            }
        }

        gzclose($handle);

        // mysqldump writes this last. Without it the dump stopped early.
        if (! str_contains($tail, 'Dump completed')) {
            $this->error('The dump has no completion marker, so it stopped early. Do not rely on it.');

            return self::FAILURE;
        }

        $this->line('  completion marker: present');

        $expected = $this->liveTables();
        $missing = array_diff($expected, $tables);

        if ($missing !== []) {
            $this->error('The dump is missing tables the database has: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->line('  tables: all '.count($expected).' present');
        $this->newLine();
        $this->warn('A full restore still needs a person. On the server:');
        $this->line('  mysql -u <user> -p -e "CREATE DATABASE rihla_restore_test"');
        $this->line('  gunzip -c '.$backup.' | mysql -u <user> -p rihla_restore_test');
        $this->line('  mysql -u <user> -p -e "DROP DATABASE rihla_restore_test"');

        return self::SUCCESS;
    }

    /**
     * The live tables, unqualified.
     *
     * `getTableListing()` returns schema-qualified names by default — `main.media`
     * on SQLite, `rihla.media` on MySQL — and a mysqldump writes the bare name.
     * Left qualified, every table looks missing.
     *
     * @return list<string>
     */
    private function liveTables(): array
    {
        return collect(DB::connection($this->option('connection') ?: null)->getSchemaBuilder()->getTableListing(schemaQualified: false))
            ->reject(fn ($table) => in_array($table, self::VOLATILE, true))
            ->values()
            ->all();
    }

    /**
     * Every table the live database has must exist in the backup, and no
     * table that carries content may come back empty.
     *
     * @param  callable(string): int  $countInBackup
     */
    private function compareTables(callable $countInBackup): int
    {
        $live = DB::connection($this->option('connection') ?: null);
        $problems = [];

        foreach ($this->liveTables() as $table) {
            $liveRows = $live->table($table)->count();

            try {
                $restored = $countInBackup($table);
            } catch (\Throwable $e) {
                $problems[] = "{$table}: not in the backup at all";

                continue;
            }

            if ($liveRows > 0 && $restored === 0) {
                $problems[] = "{$table}: {$liveRows} rows live, none in the backup";
            }

            $this->line(sprintf('  %-22s %6d live  %6d in backup', $table, $liveRows, $restored));
        }

        if ($problems !== []) {
            $this->newLine();

            foreach ($problems as $problem) {
                $this->error('  '.$problem);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('The backup opens, passes its own integrity check, and carries every table.');

        return self::SUCCESS;
    }
}
