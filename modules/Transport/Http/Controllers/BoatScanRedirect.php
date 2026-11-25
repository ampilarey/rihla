<?php

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Transport\Filament\Pages\BoatScan;
use Modules\Transport\Services\TransportDesk;

/**
 * Where a ticket's QR goes — docs/TRANSPORT_PLAN.md (T3, T5). Rihla's crew
 * board from the staff panel and an operator's crew from their own, so the
 * code points here and this sends each to the boarding page they can open:
 * the staff panel for staff, else the panel of the operator who runs that
 * boat, when the person works for them.
 */
class BoatScanRedirect extends Controller
{
    public function __invoke(Request $request, TransportDesk $desk): RedirectResponse
    {
        $token = (string) $request->query('t', '');
        $user = $request->user();

        if ($user !== null && $user->canAccessPanel(Filament::getPanel('staff'))) {
            return redirect()->to(BoatScan::getUrl(['t' => $token], panel: 'staff'));
        }

        $ticket = $desk->resolve($token);
        $operator = $ticket?->booking()->firstOrFail()->sailing()->firstOrFail()->route()->firstOrFail()->operator()->first();

        abort_if($user === null || $operator === null || ! $user->operators()->whereKey($operator->getKey())->exists(), 403);

        return redirect()->to(BoatScan::getUrl(['t' => $token], panel: 'operator', tenant: $operator));
    }

    /** What the QR on each ticket carries. */
    public static function urlFor(string $token): string
    {
        return route('transport.scan', ['t' => $token]);
    }
}
