<?php

namespace App\Http\Middleware;

use App\Models\Partner;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Modules\Companies\Models\SupportVisit;
use Modules\Companies\Services\SupportAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page somebody from Rihla opens inside a company under a support
 * grant is written down where the company sees it — companies plan, Phase
 * 5c. Page loads only: the Livewire updates a page makes as it is used are
 * part of that page, and recording each would bury the list.
 */
class RecordSupportVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Filament::getTenant();
        $user = $request->user();

        if ($request->isMethod('GET') && $company instanceof Partner && $user instanceof User
            && ! $user->companies()->whereKey($company->getKey())->exists()
            && ($grant = SupportAccess::liveFor($company, $user)) !== null) {
            SupportVisit::create([
                'support_grant_id' => $grant->getKey(),
                'company_id' => $company->getKey(),
                'user_id' => $user->getKey(),
                'path' => substr('/'.ltrim($request->path(), '/'), 0, 500),
                'visited_at' => now(),
            ]);
        }

        return $next($request);
    }
}
