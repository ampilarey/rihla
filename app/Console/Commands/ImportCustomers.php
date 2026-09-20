<?php

namespace App\Console\Commands;

use App\Services\Import\CustomerImport;
use Illuminate\Console\Command;

/**
 * Historical customers, from a spreadsheet.
 *
 * **Dry run by default.** `--write` is the only thing that changes the
 * database, and the plan's risk register asks for exactly that: an import
 * that half-succeeds against a live customer table leaves the second half
 * to be done by hand against a table that has already moved.
 */
class ImportCustomers extends Command
{
    protected $signature = 'customers:import
        {file : A CSV with a header row. Known columns: name, email, phone, national_id, address, notes}
        {--write : Actually create the customers. Without this, nothing is written}
        {--show-matched : List rows that are already on file, not just count them}';

    protected $description = 'Import historical customers from a spreadsheet, without creating duplicates';

    public function handle(CustomerImport $import): int
    {
        $file = (string) $this->argument('file');

        if (! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        $plan = $import->plan($file);

        if ($plan === []) {
            $this->warn('That file has no rows.');

            return self::SUCCESS;
        }

        $byOutcome = collect($plan)->groupBy('outcome');

        $this->newLine();
        $this->line(sprintf('Read %d rows from %s.', count($plan), basename($file)));
        $this->newLine();

        // Everything a person has to decide about, first and in full.
        // Counting them and moving on is how an import's problems get
        // discovered a month later.
        foreach ([CustomerImport::AMBIGUOUS, CustomerImport::SKIPPED] as $outcome) {
            foreach ($byOutcome->get($outcome, collect()) as $entry) {
                $this->warn(sprintf('Line %d — %s: %s', $entry['line'], $entry['row']['name'] ?: '(no name)', $entry['because']));
            }
        }

        if ($this->option('show-matched')) {
            foreach ($byOutcome->get(CustomerImport::MATCHED, collect()) as $entry) {
                $this->line(sprintf('Line %d — %s: %s', $entry['line'], $entry['row']['name'], $entry['because']));
            }
        }

        $this->newLine();
        $this->table(
            ['Outcome', 'Rows', 'What happens'],
            [
                ['New', $byOutcome->get(CustomerImport::NEW, collect())->count(), 'Created'],
                ['Already on file', $byOutcome->get(CustomerImport::MATCHED, collect())->count(), 'Left exactly as they are'],
                ['Needs a person', $byOutcome->get(CustomerImport::AMBIGUOUS, collect())->count(), 'Nothing — decide by hand'],
                ['Skipped', $byOutcome->get(CustomerImport::SKIPPED, collect())->count(), 'Nothing'],
            ],
        );

        if (! $this->option('write')) {
            $this->newLine();
            $this->info('Dry run. Nothing was written. Add --write to create the new customers.');

            return self::SUCCESS;
        }

        $created = $import->apply($plan);

        $this->newLine();
        $this->info(sprintf('%d customers created. Nothing existing was changed.', $created));

        return self::SUCCESS;
    }
}
