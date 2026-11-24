<?php

namespace Modules\Transport;

use Illuminate\Support\ServiceProvider;
use Modules\Transport\Console\Commands\ExpireBoatHolds;

/**
 * Transport — launches, speedboats and cargo (docs/TRANSPORT_PLAN.md).
 */
class TransportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireBoatHolds::class]);
        }
    }
}
