<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Take a database backup before anything is allowed to change it.
 *
 * The deploy script runs `migrate --force`, which on production means a
 * schema change against real passports, bookings and payments. Nothing in
 * this project took a copy first: the test deploy has run `migrate --force`
 * unattended since it was written, which is fine for test and is exactly what
 * must not happen to production unattended.
 *
 * This command is the gate. It exits non-zero if it cannot produce a file it
 * has verified, and the deploy script stops when it does.
 */
class BackupDatabase extends Command
{
    protected $signature = 'rihla:backup
                            {--path= : Directory to write into (default storage/app/backups)}
                            {--keep=10 : How many previous backups to retain}
                            {--connection= : Which database connection to dump (default: the app default)}';

    protected $description = 'Dump the database to a timestamped, gzipped file';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        // A snapshot taken mid-transaction is not a snapshot of anything, and
        // SQLite refuses to VACUUM from inside one. Say so rather than
        // returning a file nobody can trust.
        if (DB::connection($connection)->transactionLevel() > 0) {
            $this->error('A transaction is open on this connection. Refusing to take a backup inside it.');

            return self::FAILURE;
        }

        $directory = $this->option('path') ?: storage_path('app/backups');
        File::ensureDirectoryExists($directory);

        $stamp = now()->format('Y-m-d_His');

        $target = match ($config['driver']) {
            'mysql', 'mariadb' => $this->dumpMysql($config, "{$directory}/rihla-{$stamp}.sql.gz"),
            'sqlite' => $this->copySqlite($config, "{$directory}/rihla-{$stamp}.sqlite"),
            default => null,
        };

        if ($target === null) {
            $this->error("No backup strategy for driver [{$config['driver']}]. Refusing to continue.");

            return self::FAILURE;
        }

        // A dump that exists but is empty is worse than none: it looks like a
        // safety net while being a hole.
        if (! File::exists($target) || File::size($target) < 1024) {
            $this->error('The backup is missing or implausibly small. Refusing to report success.');

            return self::FAILURE;
        }

        $this->info(sprintf('Backup written: %s (%s KB)', $target, number_format(File::size($target) / 1024)));

        $this->prune($directory);

        return self::SUCCESS;
    }

    private function dumpMysql(array $config, string $target): ?string
    {
        // Credentials go in the environment, never on the command line, where
        // any other user on the host can read them out of `ps`.
        $process = Process::fromShellCommandline(
            'mysqldump --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" '
            .'--single-transaction --quick --routines --events '
            .'--no-tablespaces "$DB_NAME" | gzip > "$DB_TARGET"',
            null,
            [
                'MYSQL_PWD' => (string) $config['password'],
                'DB_HOST' => (string) $config['host'],
                'DB_PORT' => (string) $config['port'],
                'DB_USER' => (string) $config['username'],
                'DB_NAME' => (string) $config['database'],
                'DB_TARGET' => $target,
            ],
            null,
            600,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('mysqldump failed: '.trim($process->getErrorOutput()));

            return null;
        }

        return $target;
    }

    /**
     * `VACUUM INTO` rather than a file copy.
     *
     * It produces a consistent snapshot even while the database is being
     * written to, and it works for any SQLite connection — including the
     * in-memory one the tests run on, which has no file to copy and so made
     * the first version of this command untestable.
     */
    private function copySqlite(array $config, string $target): ?string
    {
        try {
            DB::connection($this->option('connection') ?: null)->statement('VACUUM INTO ?', [$target]);
        } catch (\Throwable $e) {
            $this->error('SQLite backup failed: '.$e->getMessage());

            return null;
        }

        return $target;
    }

    /** Keep the most recent few; a full disk is its own outage. */
    private function prune(string $directory): void
    {
        $keep = max(1, (int) $this->option('keep'));

        $backups = collect(File::files($directory))
            ->filter(fn ($file) => str_starts_with($file->getFilename(), 'rihla-'))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        $backups->slice($keep)->each(function ($file) {
            File::delete($file->getPathname());
            $this->line('  pruned '.$file->getFilename());
        });
    }
}
