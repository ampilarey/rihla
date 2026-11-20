<?php

namespace Modules\Content;

use Illuminate\Support\ServiceProvider;
use Modules\Content\Console\Commands\PruneAssistantLog;

/**
 * What the site publishes, as a module — docs/MODULES_PLAN.md: trips,
 * articles and the knowledge centre, the Umrah guide and ziyarah, the
 * homepage's banners and "why" blocks, the people pages, the learning
 * academy, the scholar's desk and the pilgrim assistant.
 */
class ContentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneAssistantLog::class]);
        }
    }
}
