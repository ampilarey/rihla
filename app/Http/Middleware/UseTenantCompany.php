<?php

namespace App\Http\Middleware;

use App\Models\Partner;
use App\Support\CompanyContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inside a company's panel, every query works for that company — companies
 * plan, Phase 2a.
 *
 * Filament has resolved the tenant from the URL and checked the person's
 * membership by the time this runs, so the company scope and gate from
 * Phase 1 simply follow it. Persistent, so a Livewire update — every button
 * on the page — runs for the same company as the page it came from.
 */
class UseTenantCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Filament::getTenant();

        abort_unless($company instanceof Partner && $company->is_umrah_company, 404);

        app(CompanyContext::class)->use((int) $company->getKey());

        return $next($request);
    }
}
