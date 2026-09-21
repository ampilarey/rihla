<?php

namespace App\Console\Commands;

use App\Support\Anonymisation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Scrub personal data out of the connected database — §10.4.
 *
 * ## The workflow this is for
 *
 * `test.rihla.mv` auto-deploys from `main` and is reachable on the public
 * internet. The moment somebody restores a production backup onto it to
 * reproduce a bug, real passport numbers are on a public host.
 *
 * So: restore the production backup **into the test database**, run this
 * there, then create an account with `admin:create`. Production is never
 * touched, because this command cannot run against it — see below.
 *
 * ## It refuses on production, with no override
 *
 * There is no `--force` that reaches production and no environment variable
 * that unlocks it. A destructive command with an escape hatch is a command
 * that will one day be run with the escape hatch, at two in the morning, by
 * somebody who is sure this is the test box.
 *
 * ## It refuses when the schema has moved
 *
 * {@see Anonymisation} classifies **every** table. If the database holds a
 * table nobody has classified, this stops rather than scrubbing what it
 * recognises and leaving the rest — because what it would leave is exactly
 * the newest table, and the safe reading of an unclassified table is that
 * it holds somebody's passport number.
 */
class Anonymise extends Command
{
    protected $signature = 'data:anonymise
        {--force : Skip the confirmation prompt (for a scripted test restore)}
        {--dry-run : Report what would change and change nothing}';

    protected $description = 'Replace personal data in the connected database with stand-ins, for a test server';

    public function handle(): int
    {
        // No override, deliberately. See the class docblock.
        if (app()->environment('production')) {
            $this->error('Refusing to run: APP_ENV is production.');
            $this->line('This command scrubs the database it is connected to. Restore the backup onto the test server and run it there.');

            return self::FAILURE;
        }

        $tables = $this->tables();
        $unclassified = Anonymisation::unclassified($tables);

        if ($unclassified !== []) {
            $this->error('Refusing to run: '.count($unclassified).' table(s) are not classified in App\Support\Anonymisation.');

            foreach ($unclassified as $table) {
                $this->line('  - '.$table);
            }

            $this->newLine();
            $this->line('Add each to SCRUB, EMPTY_OUT or KEEP. Scrubbing what is recognised and leaving the rest');
            $this->line('would leave exactly the newest table, which is the one most likely to hold something personal.');

            return self::FAILURE;
        }

        foreach (Anonymisation::stale($tables) as $missing) {
            $this->warn('Classified but not in this database: '.$missing);
        }

        $dry = (bool) $this->option('dry-run');

        if (! $dry && ! $this->option('force') && ! $this->confirm(
            'This permanently replaces personal data in "'.DB::connection()->getDatabaseName().'". Continue?',
        )) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $scrubbed = $this->scrub($dry);
        $emptied = $this->emptyOut($dry);

        $this->newLine();
        $this->info(($dry ? 'Would scrub ' : 'Scrubbed ').$scrubbed.' row(s) and '
            .($dry ? 'empty ' : 'emptied ').$emptied.' row(s) out.');

        if (! $dry) {
            $this->line('No account on this database can be signed in to. Make one with: php artisan admin:create <email> <password>');
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return collect(Schema::getTableListing())
            ->map(fn (string $table): string => Str::afterLast($table, '.'))
            ->reject(fn (string $table): bool => str_starts_with($table, 'sqlite_'))
            ->values()
            ->all();
    }

    private function scrub(bool $dry): int
    {
        $total = 0;

        foreach (Anonymisation::SCRUB as $table => $columns) {
            if ($columns === [] || ! Schema::hasTable($table)) {
                continue;
            }

            $present = array_filter(
                $columns,
                fn (string $strategy, string $column): bool => Schema::hasColumn($table, $column),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($present === []) {
                continue;
            }

            $rows = DB::table($table)->select('id')->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $this->line(sprintf('%-24s %5d row(s) · %s', $table, $rows->count(), implode(', ', array_keys($present))));
            $total += $rows->count();

            if ($dry) {
                continue;
            }

            // Row by row, because every stand-in is keyed to the row's id:
            // two customers must not both become "Placeholder Person", or
            // the test data stops being usable for the thing it is for.
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(
                    collect($present)
                        ->map(fn (string $strategy): ?string => self::standIn($strategy, (int) $row->id))
                        ->all(),
                );
            }
        }

        return $total;
    }

    private function emptyOut(bool $dry): int
    {
        $total = 0;

        foreach (Anonymisation::EMPTY_OUT as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->count();

            if ($count === 0) {
                continue;
            }

            $this->line(sprintf('%-24s %5d row(s) · emptied', $table, $count));
            $total += $count;

            if (! $dry) {
                DB::table($table)->delete();
            }
        }

        return $total;
    }

    /**
     * A stand-in that is obviously a stand-in.
     *
     * Keyed to the row id so the data stays navigable — "Placeholder Person
     * 41" appears everywhere that customer does — and recognisable on sight,
     * because the worst outcome of a scrub is somebody believing a name on
     * the test server is real and acting on it.
     */
    public static function standIn(string $strategy, int $id): ?string
    {
        return match ($strategy) {
            'name' => 'Placeholder Person '.$id,
            // .invalid is reserved by RFC 2606 and can never be delivered
            // to, so a stray send from the test server reaches nobody.
            'email' => 'person'.$id.'@example.invalid',
            // 3xx xxxx: the Maldives issues 7xx and 9xx mobiles, so this
            // cannot be somebody's telephone.
            'phone' => '3'.str_pad((string) ($id % 1000000), 6, '0', STR_PAD_LEFT),
            'text' => 'Placeholder text, scrubbed for the test server.',
            'token' => bin2hex(random_bytes(32)),
            // For a column that points at a file and cannot be null. The
            // pointer is dead either way; this says so instead of failing
            // the whole run on a NOT NULL constraint.
            'gone' => '(file deleted)',
            default => null,
        };
    }
}
