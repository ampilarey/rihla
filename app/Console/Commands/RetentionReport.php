<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What personal data is held, and how old it is — §10.4.
 *
 * ## Why this reports and never deletes
 *
 * §10.4 asks for a retention *policy*, and a policy is a business and
 * legal decision: Maldivian tax and company record rules, what the
 * Ministry expects of a licensed operator, what Rihla is willing to
 * promise a customer. None of that has been stated, and a command that
 * invented a number would make the number the policy by accident.
 *
 * So this answers the question that has to come first — *what are we
 * actually holding, and since when* — which nobody can answer today.
 * Once `config/retention.php` carries a number, the same report says how
 * much is past it. Erasing stays deliberate and one person at a time,
 * through {@see ForgetCustomer}, because a sweep that deletes by age
 * deletes somebody mid-dispute as readily as somebody long gone.
 */
class RetentionReport extends Command
{
    protected $signature = 'data:retention';

    protected $description = 'Report what personal data is held and how old it is';

    /** Age brackets, in years, and the label for each. */
    private const BRACKETS = [
        [0, 1, 'under a year'],
        [1, 3, '1 to 3 years'],
        [3, 7, '3 to 7 years'],
        [7, null, 'over 7 years'],
    ];

    /** @var array<string, string> table => what it holds, in plain words */
    private const CATEGORIES = [
        'customers' => 'names, contacts, national IDs',
        'travellers' => 'passport numbers, dates of birth, medical notes',
        'documents' => 'identity documents (the files are on disk)',
        'enquiries' => 'people who asked and may never have booked',
        'assistant_exchanges' => 'questions put to the assistant',
        'audit_logs' => 'who did what in the staff panel',
    ];

    public function handle(): int
    {
        $rows = [];
        $anyPolicy = false;

        foreach (self::CATEGORIES as $table => $what) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $row = ['table' => $table, 'what' => $what];

            foreach (self::BRACKETS as [$from, $to, $label]) {
                $row[$label] = $this->countBetween($table, $from, $to);
            }

            $years = config('retention.years.'.$table);

            if (is_numeric($years)) {
                $anyPolicy = true;
                $row['past the policy'] = (string) $this->countBetween($table, (int) $years, null);
            } else {
                $row['past the policy'] = 'no policy set';
            }

            $rows[] = $row;
        }

        $this->table(
            array_merge(['table', 'what'], array_column(self::BRACKETS, 2), ['past the policy']),
            $rows,
        );

        $this->newLine();

        if (! $anyPolicy) {
            $this->warn('No retention period is set for anything.');
            $this->line('That is a legal and commercial decision, not a technical one, so nothing here');
            $this->line('guesses at it. Set the years in config/retention.php once it has been decided.');
        }

        $this->line('Nothing was deleted. Erasing one person is `php artisan data:forget <customer>`.');

        return self::SUCCESS;
    }

    private function countBetween(string $table, int $fromYears, ?int $toYears): int
    {
        $query = DB::table($table)->where('created_at', '<=', now()->subYears($fromYears));

        if ($toYears !== null) {
            $query->where('created_at', '>', now()->subYears($toYears));
        }

        return $query->count();
    }
}
