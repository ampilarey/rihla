<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Services\Companies\CompanyExport;
use App\Support\AuditContext;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Everything a company holds, in one zip, from the server's command line
 * (companies plan, Phase 5d) — for an export too large to download through
 * the panel. Rihla's operator tool, so it reads across every company.
 */
class ExportCompany extends Command
{
    protected $signature = 'company:export {company : the company\'s slug}';

    protected $description = 'Export every record and file an Umrah company holds, as CSVs and files in one zip';

    public function handle(CompanyExport $export): int
    {
        $company = Partner::where('slug', (string) $this->argument('company'))->where('is_umrah_company', true)->first();

        if ($company === null) {
            $this->error('No Umrah company has that slug.');

            return self::FAILURE;
        }

        AuditContext::use(AuditContext::CONSOLE);

        try {
            $relative = app(CompanyContext::class)->acrossEveryCompany(fn (): string => $export->make($company));
        } finally {
            AuditContext::reset();
        }

        $this->info('Written to '.Storage::disk('local')->path($relative));
        $this->warn('It holds passport scans. Hand it only to the company, and delete it from the server afterwards.');

        return self::SUCCESS;
    }
}
