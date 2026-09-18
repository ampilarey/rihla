<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response carried no security headers at all, and advertised its exact
 * PHP version in every one.
 *
 * Content-Security-Policy is deliberately absent. The site has inline script
 * and style blocks, and colours chosen in the admin panel are written into
 * inline style attributes, so an enforcing policy would need those refactored
 * onto nonces first. Shipping a policy loose enough to permit 'unsafe-inline'
 * would look like protection while providing almost none, which is worse than
 * having none: see the note in docs/WEBSITE_UPGRADE_PLAN.md §10.
 */
class SecurityHeaders
{
    /**
     * Features the site does not use. Anything absent keeps its browser
     * default, which matters for fullscreen: the gallery embeds YouTube, and
     * naming a feature here would stop the video expanding.
     */
    private const DENIED_FEATURES = [
        'geolocation',
        'camera',
        'microphone',
        'payment',
        'usb',
        'magnetometer',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // PHP adds this before the application sees the response; it names the
        // exact patch version, which is a free hint to anyone looking for an
        // unpatched one.
        $response->headers->remove('X-Powered-By');
        header_remove('X-Powered-By');

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Clickjacking. It matters most for the admin panel, where a framed
        // page could be used to trick a signed-in member of staff into
        // clicking something they cannot see.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Send the full URL within this site, only the origin when leaving it.
        // Paths here can name a trip or a guide step, which is not information
        // to hand to every outbound link.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $response->headers->set(
            'Permissions-Policy',
            implode(', ', array_map(
                static fn (string $feature) => "{$feature}=()",
                self::DENIED_FEATURES,
            )),
        );

        $maxAge = (int) config('security.hsts_max_age');

        // Only over HTTPS: sending it on a plain request is meaningless, and
        // over local HTTP it would teach a developer's browser to refuse the
        // site it is being served.
        if ($maxAge > 0 && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}");
        }

        return $response;
    }
}
