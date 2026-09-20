<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Setting;
use App\Support\PackageFilters;
use App\Support\PackageFinderOptions;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public package pages.
 *
 * Additive: `/trips` and `/trips/{slug}` are untouched and still served by
 * TripController. A package is the product and a departure is one dated run
 * of it, which is what makes seats, a countdown, an itinerary and hotel
 * distances possible — none of which the flat `trips` table could hold.
 */
class PackageController extends Controller
{
    public function index(Request $request): View
    {
        $filters = PackageFilters::fromRequest($request);

        $packages = $filters->apply(Package::published())
            ->with([
                // Only departures that are themselves published, and only
                // ones that have not left. A package whose every departure
                // is in the past is still listed — it shows "no dates
                // announced" rather than vanishing, because the page is also
                // how someone asks for the next one.
                'publishedDepartures' => fn ($query) => $query->upcoming()->with(['priceTiers', 'hotels']),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('packages.index', [
            'packages' => $packages,
            'filters' => $filters,
            'options' => PackageFinderOptions::build(),
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    /**
     * No `$locale` parameter, even though the route is prefixed with one:
     * SetLocale consumes it before the controller runs, which is why
     * TripController::show() takes only a slug too.
     */
    public function show(string $slug): View
    {
        $package = Package::published()
            ->where('slug', $slug)
            ->with([
                'publishedDepartures' => fn ($query) => $query
                    ->upcoming()
                    ->with(['priceTiers', 'hotels', 'itinerary']),
            ])
            ->firstOrFail();

        return view('packages.show', [
            'package' => $package,
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }
}
