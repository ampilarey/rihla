<?php

namespace Modules\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Support\CurrentSite;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Modules\Companies\Models\CompanySite;
use Modules\Umrah\Http\Controllers\EnquiryController;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\Package;

/**
 * A company's own home and contact pages — companies plan, Phase 4a.
 *
 * Reached through rihla.mv's own `home` and `contact` routes, which hand
 * over when the request is on a company's address. Every query here is
 * walled to the company by the scope {@see CurrentSite} put in place; the
 * pages read nothing that has no company (trips, articles, banners,
 * settings), because those are Rihla's own.
 */
class CompanySiteController extends Controller
{
    public function home(): View
    {
        $departures = Departure::open()
            ->upcoming()
            ->whereHas('package', function ($package): void {
                /** @var Builder<Package> $package */
                $package->published();
            })
            ->with(['package', 'priceTiers', 'hotels'])
            ->orderBy('date_start')
            ->take(6)
            ->get();

        return view('sites.home', [
            'company' => $this->company(),
            'site' => $this->site(),
            'departures' => $departures,
        ]);
    }

    public function contact(): View
    {
        return view('sites.contact', [
            'company' => $this->company(),
            'site' => $this->site(),
            'packages' => EnquiryController::packageOptions(),
        ]);
    }

    private function company(): Partner
    {
        return app(CurrentSite::class)->company() ?? abort(404);
    }

    private function site(): CompanySite
    {
        return app(CurrentSite::class)->site() ?? abort(404);
    }
}
