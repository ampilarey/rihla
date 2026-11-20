<?php

namespace Modules\Umrah;

use Illuminate\Support\ServiceProvider;
use Modules\Umrah\Console\Commands\ExpireSeatHolds;

/**
 * The Umrah line of business, as a module — docs/MODULES_PLAN.md:
 * packages and their prices, departures and everything planned on them,
 * bookings and travellers, seats and the waiting list, documents and
 * their reading, visas and Nusuk permits, excursions, and (in a later
 * step) ground operations, the CRM, broadcasts and the portals.
 */
class UmrahServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireSeatHolds::class]);
        }
    }
}
