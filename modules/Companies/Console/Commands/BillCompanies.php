<?php

namespace Modules\Companies\Console\Commands;

use Illuminate\Console\Command;
use Modules\Companies\Services\CompanyBilling;

/**
 * The daily billing run for Umrah companies: issue what is due, remind,
 * and suspend only if `companies.billing.suspend_after_days` says so.
 * Idempotent: a second run on the same day issues and sends nothing.
 */
class BillCompanies extends Command
{
    protected $signature = 'companies:bill';

    protected $description = 'Issue Umrah companies\' invoices and send their reminders';

    public function handle(CompanyBilling $billing): int
    {
        $counts = $billing->run();

        $this->info(sprintf(
            'Issued %d invoice(s); %d reminder(s), %d overdue notice(s), %d suspension(s).',
            $counts['issued'], $counts['reminded'], $counts['overdue'], $counts['suspended'],
        ));

        return self::SUCCESS;
    }
}
