<?php

namespace Modules\Companies;

use Illuminate\Support\ServiceProvider;
use Modules\Companies\Console\Commands\BillCompanies;
use Modules\Companies\Console\Commands\ExportCompany;

/**
 * The Umrah companies Rihla hosts, as a module — docs/MODULES_PLAN.md:
 * adding, billing, exporting and closing a company, its memberships,
 * website and support access, and its panel's own pages.
 *
 * What keeps companies apart (CompanyContext, the company gate,
 * BelongsToCompany, the company-site middleware) stays in Core, because
 * every Umrah record in every module depends on it.
 */
class CompaniesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([BillCompanies::class, ExportCompany::class]);
        }
    }
}
