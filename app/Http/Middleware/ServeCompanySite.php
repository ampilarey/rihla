<?php

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company's address serves that company's site — companies plan, Phase 4a.
 *
 * Global, and first: it must run before route model binding looks a package
 * up (or the lookup runs for Rihla and the company's own package is a 404),
 * and before the Filament panels, which do not use the `web` group, so that
 * `coral.rihla.mv/staff` is refused rather than served as Rihla's back office
 * under Coral's name.
 *
 * Any other host passes straight through: rihla.mv is untouched.
 */
class ServeCompanySite
{
    /** The site's own files outside the language-prefixed pages. */
    private const FILES = ['sitemap.xml', 'robots.txt', 'manifest.webmanifest', 'site-sw.js', 'offline'];

    public function handle(Request $request, Closure $next): Response
    {
        // A request on any other host is never a company's site, even in an
        // application that served one a moment ago.
        if (app(CurrentSite::class)->forget() && ($rihla = app(CompanyContext::class)->rihlaId()) !== null) {
            app(CompanyContext::class)->use($rihla);
        }

        $isCompanyName = CurrentSite::label($request->getHost()) !== null;
        $company = CurrentSite::companyFor($request->getHost());

        // Neither a company's name nor a live company's own domain: rihla.mv
        // (or anything else) as before.
        if (! $isCompanyName && $company === null) {
            return $next($request);
        }

        if ($company === null) {
            // A guesthouse host's name is answered by its own redirect when
            // host subdomains live on the same domain (§16 Phase 16). Any
            // other unknown name is a 404, never a second copy of rihla.mv,
            // and says nothing about whether a company of that name exists.
            abort_unless(
                filled(config('marketplace.host_subdomains.domain'))
                    && config('marketplace.host_subdomains.domain') === config('companies.site_domain'),
                404,
            );

            return $next($request);
        }

        app(CurrentSite::class)->use($company);
        app(CompanyContext::class)->use((int) $company->getKey());

        // The site's own pages only: the bare address, which sends a visitor
        // on to their language, the language-prefixed public routes, and
        // the site's own sitemap, robots.txt and installable app.
        // Panels, the API, health and Pulse are rihla.mv's.
        $path = trim($request->path(), '/');
        abort_unless($path === '' || in_array($path, self::FILES, true) || preg_match('#^site-icon-\d+\.png$#', $path) === 1 || preg_match('#^(en|dv|ar)(/|$)#', $path) === 1, 404);

        return $next($request);
    }
}
