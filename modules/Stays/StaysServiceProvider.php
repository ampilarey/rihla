<?php

namespace Modules\Stays;

use Illuminate\Support\ServiceProvider;
use Modules\Stays\Console\Commands\ExpireStayHolds;
use Modules\Stays\Console\Commands\IssueHostStatements;
use Modules\Stays\Console\Commands\StaysDemo;
use Modules\Stays\Console\Commands\SyncCalendars;

/**
 * The Stays line of business, as a module — docs/MODULES_PLAN.md:
 * properties, rooms, rates and calendars, stays and their guests, bills,
 * messages and reviews, the marketplace's money (commission, statements,
 * payouts), and the guesthouse hosts' own panel.
 */
class StaysServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireStayHolds::class, IssueHostStatements::class, StaysDemo::class, SyncCalendars::class]);
        }
    }
}
