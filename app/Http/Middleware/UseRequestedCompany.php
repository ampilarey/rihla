<?php

namespace App\Http\Middleware;

use App\Models\Partner;
use App\Support\CompanyContext;
use App\Support\CompanyRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A print or download opened from a company's panel works for that company
 * — companies plan, Phase 2b.
 *
 * The link carries `?company={slug}` ({@see CompanyRequest::route()}). The
 * person must be an accepted member of that company, or the answer is 404:
 * a slug is not a credential, and naming somebody else's company must look
 * exactly like naming one that does not exist. With no parameter nothing
 * changes, so every Rihla link behaves as before.
 */
class UseRequestedCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->query('company');

        if (! is_string($slug) || $slug === '') {
            return $next($request);
        }

        $company = Partner::where('slug', $slug)->where('is_umrah_company', true)->first();
        $user = $request->user();

        abort_unless($company !== null && $user !== null && $user->companies()->whereKey($company->getKey())->exists(), 404);

        $request->attributes->set(CompanyRequest::ATTRIBUTE, $company);
        app(CompanyContext::class)->use((int) $company->getKey());

        return $next($request);
    }
}
