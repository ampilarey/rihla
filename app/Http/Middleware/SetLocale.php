<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('app_locale', 'en');

        if (! in_array($locale, ['en', 'dv'])) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        // Set HTML direction for RTL support
        if ($locale === 'dv') {
            view()->share('isRTL', true);
            view()->share('htmlDir', 'rtl');
            view()->share('htmlLang', 'dv');
        } else {
            view()->share('isRTL', false);
            view()->share('htmlDir', 'ltr');
            view()->share('htmlLang', 'en');
        }

        return $next($request);
    }
}
