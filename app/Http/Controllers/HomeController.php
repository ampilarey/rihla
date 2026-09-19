<?php

namespace App\Http\Controllers;

use App\Models\HeroBanner;
use App\Models\Media;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\WhySection;

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

        // Get active hero banners for current locale
        $heroBanners = HeroBanner::active()
            ->where('locale', app()->getLocale())
            ->orderBy('sort_order')
            ->get();

        // The active why section for this locale, falling back to English.
        //
        // The Dhivehi one was machine-generated — its three feature bodies
        // shared 72 of 77 characters where the English three share two — and
        // has been removed rather than paraphrased. Without a fallback, /dv
        // would simply lose the block; with one, a Dhivehi visitor sees the
        // real selling points in English until a translation exists.
        $why = cache()->remember('why_section_active_'.app()->getLocale(), 3600, function () {
            $query = fn (string $locale) => WhySection::with(['features' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
                ->where('is_active', true)
                ->where('locale', $locale)
                ->first();

            return $query(app()->getLocale()) ?? $query(config('app.fallback_locale'));
        });

        return view('home', compact('currentTrip', 'upcomingTrip', 'recentMedia', 'socialSettings', 'heroBanners', 'why'));
    }
}
