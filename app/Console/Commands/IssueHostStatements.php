<?php

namespace App\Console\Commands;

use App\Services\Hosts\Statements;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Issue last month's host statements — §16.9. Run on the 2nd, so a stay
 * checked out late on the last night of the month has been closed.
 * Idempotent: a month already issued is left as it was.
 */
class IssueHostStatements extends Command
{
    protected $signature = 'stays:statements {--month= : YYYY-MM, default last month}';

    protected $description = 'Issue monthly statements to every active host';

    public function handle(Statements $statements): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'))
            : CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();

        $count = $statements->issueForAll($month);

        $this->info("Issued {$count} statement(s) for ".$month->format('F Y').'.');

        return self::SUCCESS;
    }
}
