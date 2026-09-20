<?php

namespace App\Http\Controllers;

use App\Models\Departure;
use App\Models\HeroBanner;
use App\Models\Media;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\WhySection;
use App\Support\Personalisation;
use Illuminate\Database\Eloquent\Builder;

class HomeController extends Controller
{
    public function index()
    {
        $currentTrip = Trip::published()->current()->first();
        $upcomingTrip = Trip::published()->upcoming()->orderBy('date_start')->first();
        $recentMedia = Media::published()
            ->whereHas('trip', function ($query) {
                $query->where('status', 'past');
            })
            ->orWhereNull('trip_id')
            ->orderBy('created_at', 'desc')
            ->take(8)
            ->get();

        $socialSettings = Setting::getSocialSettings();

        // Every active banner, in whichever language the visitor is reading.
        // This used to filter by locale, because a banner in the same slot was
        // two rows — so a slot with no Dhivehi row simply vanished from /dv.
        $heroBanners = HeroBanner::active()->orderBy('sort_order')->get();

        // The active why-section, translated where a translation exists.
        //
        // The Dhivehi section was machine-generated — its three feature bodies
        // shared 72 of 77 characters where the English three share two — and
        // was removed rather than paraphrased. The fallback is now per field
        // rather than per section, so a half-translated block shows the
        // Dhivehi it has and the English for the rest, instead of falling back
        // whole.
        $why = cache()->remember(WhySection::CACHE_KEY, 3600, fn () => WhySection::with([
            'features' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
        ])->where('is_active', true)->first());

        // The three soonest departures anyone can still join, with
        // everything the cards need: price, hotels and seats. Additive — the
        // trip sections above are untouched and still render.
        $upcomingDepartures = Departure::published()
            ->upcoming()
            ->whereHas('package', function ($package): void {
                /** @var Builder<Package> $package */
                $package->published();
            })
            ->with(['package', 'priceTiers', 'hotels'])
            ->orderBy('date_start')
            ->take(3)
            ->get();

        // §4.2's "personalisation for returning users", and the whole of
        // it: one line for somebody signed in who has actually travelled
        // with Rihla. Nothing is cached — it is per-visitor and the rest
        // of this page is not, so it must not join the cached payload.
        $personal = Personalisation::for(auth()->user());

        return view('home', compact(
            'currentTrip', 'upcomingTrip', 'recentMedia', 'socialSettings',
            'heroBanners', 'why', 'upcomingDepartures', 'personal',
        ));
    }
}
