<?php

namespace Modules\Telegram;

use Illuminate\Support\ServiceProvider;
use Modules\Telegram\Console\Commands\TelegramDaily;
use Modules\Telegram\Console\Commands\TelegramWebhook;

/**
 * The staff Telegram bot (batch A4 from Hijaz) as a module —
 * docs/MODULES_PLAN.md. Its routes stay in routes/api.php and its settings
 * in config/telegram.php; this registers what Laravel would otherwise only
 * find in app/.
 */
class TelegramServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TelegramDaily::class, TelegramWebhook::class]);
        }
    }
}
