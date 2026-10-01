<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Every absolute link comes from APP_URL, never from the Host header of
 * the request that built it — site audit.
 *
 * A vhost that answers for a foreign name (the default site on a shared
 * host) would otherwise put that name into a password-reset mail or the
 * cached sitemap. Always on in production; `APP_FORCE_URL` turns it on
 * elsewhere (the test server), and tests set the config directly.
 */
final class CanonicalUrl
{
    public static function enforce(): void
    {
        $url = (string) config('app.url');

        if ($url === '' || ! (app()->isProduction() || config('app.force_url'))) {
            return;
        }

        URL::forceRootUrl($url);
        URL::forceScheme((string) (parse_url($url, PHP_URL_SCHEME) ?: 'https'));
    }
}
