<?php

namespace App\Http\Middleware;

use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a company's address, only the routes its site answers — companies
 * plan, Phase 4a. The list is {@see CurrentSite::ROUTES}: an allowlist, so a
 * page Rihla adds later is absent from every company's site until somebody
 * decides it belongs there.
 */
class KeepToCompanyRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        if (CurrentSite::active()) {
            $name = (string) $request->route()?->getName();
            abort_unless($name === 'root' || CurrentSite::allows($name), 404);
        }

        return $next($request);
    }
}
