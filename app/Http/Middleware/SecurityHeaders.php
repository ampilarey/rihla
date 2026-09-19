<?php

namespace App\Http\Middleware;

use App\Providers\Filament\StaffPanelProvider;
use App\Support\Csp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response carried no security headers at all, and advertised its exact
 * PHP version in every one.
 *
 * It now also sends a Content-Security-Policy. See policy() below for what
 * each directive is for and, more importantly, which two concessions it makes
 * and why.
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
        // Before the response is rendered: the views ask for the nonce while
        // Blade runs, which happens inside $next(). Forgetting it first means
        // a second request in the same process cannot inherit the first one's.
        Csp::forget();

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

        if (config('security.csp_enabled')) {
            $response->headers->set(
                config('security.csp_report_only')
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy',
                $this->policy($request),
            );
        }

        return $response;
    }

    /**
     * What the browser is allowed to load, and from where.
     *
     * The protection that matters is in script-src. It names a nonce, which
     * means the browser runs the scripts this application marked and refuses
     * every other one — including any that arrives inside a trip title, a
     * guide step or a media caption, which is how cross-site scripting nearly
     * always gets in. All 36 inline event handlers had to go before this could
     * be written: a nonce cannot vouch for code living in an attribute.
     *
     * Two concessions, both deliberate and both narrower than they look.
     *
     * 'unsafe-eval' is here because Alpine's standard build compiles every
     * x-data expression with new Function(). It does not re-open the hole the
     * nonce closes: an injected <script> tag is still refused, and reaching
     * eval requires code that is already running. Removing it means moving to
     *
     * @alpinejs/csp, which is its own piece of work because every inline
     * expression has to become a registered component.
     *
     * style-src keeps 'unsafe-inline' rather than a nonce. Colours chosen in
     * Admin → Settings are written into inline style attributes, which a nonce
     * cannot cover — only style-src-attr can, and that directive is not
     * supported widely enough to rely on. A blocked style attribute would mean
     * a hero banner losing its colours in whichever browsers lack it. Style
     * injection is a real but much smaller problem than script injection, and
     * this is the honest trade rather than a green tick.
     */
    /**
     * The staff panel cannot run under the site's script policy.
     *
     * Filament and Livewire emit four inline <script> blocks between them,
     * and only Livewire's can carry a nonce — Filament's `assets.blade.php`
     * writes `window.filamentData` with no way to attach one. Under the
     * nonce policy the browser refuses all four and the panel renders but
     * does nothing, which is how this was found: the page was opened and
     * looked at.
     *
     * So `script-src` on /staff is 'self' 'unsafe-inline' 'unsafe-eval',
     * and the public site keeps its nonce. The panel is behind
     * authentication and the `admin.access` permission; the public site,
     * which is where an injection would come from a stranger, is unchanged.
     * A test asserts both halves of that.
     *
     * The alternative is patching published Filament views on every upgrade.
     * See docs/adr/0003-filament-for-new-admin-modules.md.
     *
     * The Pulse dashboard is the second page in the same position, for the
     * same reason: it is Livewire, and its layout writes an inline <script>
     * this application does not render and cannot put a nonce on. It is
     * listed here by its configured path rather than by a hard-coded
     * 'pulse', so moving PULSE_PATH moves this with it — a dashboard that
     * renders blank because the policy no longer matches its URL is a
     * genuinely confusing failure.
     *
     * Both are staff-only pages behind authentication. The public site,
     * which is where an injection would arrive from a stranger, keeps the
     * nonce. A test asserts both halves of that.
     */
    private function needsInlineScripts(Request $request): bool
    {
        $pulse = trim((string) config('pulse.path'), '/');

        return $request->is(StaffPanelProvider::PATH, StaffPanelProvider::PATH.'/*')
            || ($pulse !== '' && $request->is($pulse, $pulse.'/*'));
    }

    private function policy(Request $request): string
    {
        // A nonce and 'unsafe-inline' in the same directive is not a
        // belt-and-braces arrangement: a browser that understands nonces
        // ignores 'unsafe-inline' entirely. So the panel gets a policy with
        // no nonce at all, and every other page keeps the strict one.
        $script = $this->needsInlineScripts($request)
            ? ["'self'", "'unsafe-inline'", "'unsafe-eval'"]
            : ["'self'", "'nonce-".Csp::nonce()."'", "'unsafe-eval'"];

        $connect = ["'self'"];

        // With `npm run dev` running, Vite serves the bundle and its hot-reload
        // client from its own origin over http and ws. Without this the policy
        // would block the dev server and every local page would load unstyled.
        if (file_exists(public_path('hot'))) {
            $origin = rtrim((string) file_get_contents(public_path('hot')), "\n");

            if ($origin !== '') {
                $script[] = $origin;
                $connect[] = $origin;
                $connect[] = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $origin);
            }
        }

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],

            // Nothing on this site is a plugin, and <object> is a classic way
            // to run something the other directives never see.
            'object-src' => ["'none'"],

            // A form that posts somewhere else is how an injected login box
            // harvests a password.
            'form-action' => ["'self'"],

            // The same promise as X-Frame-Options, for browsers that prefer
            // this one. It matters most for the admin panel.
            'frame-ancestors' => ["'self'"],

            'script-src' => $script,
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net', 'https://fonts.googleapis.com'],
            'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net', 'https://fonts.gstatic.com'],

            // data: is here for the inline SVG and the PWA icons; the YouTube
            // hosts serve video thumbnails on the gallery.
            'img-src' => ["'self'", 'data:', 'https://img.youtube.com', 'https://i.ytimg.com'],

            'media-src' => ["'self'"],
            'frame-src' => ['https://www.youtube.com', 'https://www.youtube-nocookie.com', 'https://player.vimeo.com'],
            'connect-src' => $connect,
        ];

        return implode('; ', array_map(
            static fn (string $name, array $values) => $name.' '.implode(' ', $values),
            array_keys($directives),
            $directives,
        ));
    }
}
