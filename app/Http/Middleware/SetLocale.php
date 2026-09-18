<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** Locales the public site is published in. */
    public const SUPPORTED = ['en', 'dv'];

    /**
     * Resolve the request's locale and make it the default for URL generation.
     *
     * Public URLs carry their locale as the first path segment (/en/…, /dv/…).
     * Admin, auth and the JSON API are not localised in the path and fall back
     * to the session, so both styles work side by side.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        // Remembered so unprefixed pages (admin, login) and the bare domain
        // stay in the language the visitor last chose.
        session(['app_locale' => $locale]);

        // This is what keeps the views untouched: every route('…') call now
        // emits the current language's URL without being passed a locale.
        URL::defaults(['locale' => $locale]);

        // Controllers keep their existing signatures. Without this the prefix
        // would arrive as their first argument, so TripController::show()
        // would receive 'en' where it expects a slug.
        $request->route()?->forgetParameter('locale');

        $isRtl = $locale === 'dv';

        view()->share('isRTL', $isRtl);
        view()->share('htmlDir', $isRtl ? 'rtl' : 'ltr');
        view()->share('htmlLang', $locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $fromPath = $request->route()?->parameter('locale');

        if (is_string($fromPath) && in_array($fromPath, self::SUPPORTED, true)) {
            return $fromPath;
        }

        $fromSession = session('app_locale');

        if (is_string($fromSession) && in_array($fromSession, self::SUPPORTED, true)) {
            return $fromSession;
        }

        // First visit, no choice made yet: honour the browser. This is the only
        // place it is consulted — once the session carries a locale, the
        // visitor's own choice wins over whatever their browser advertises.
        return $request->getPreferredLanguage(self::SUPPORTED) ?? 'en';
    }
}
