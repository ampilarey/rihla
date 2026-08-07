<?php

namespace App\Providers;

use App\Services\LocaleService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Share locale information with all views
        View::composer('*', function ($view) {
            $view->with([
                'htmlLang' => LocaleService::getHtmlLang(),
                'htmlDir' => LocaleService::getHtmlDir(),
                'isRTL' => LocaleService::isRTL(),
                'currentLocale' => LocaleService::getCurrentLocale(),
                'availableLocales' => LocaleService::getAvailableLocales(),
                'localeInfo' => LocaleService::getLocaleInfo(LocaleService::getCurrentLocale())
            ]);
        });
    }
}
