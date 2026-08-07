<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\HeroBanner;
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

        // Get active why section with features for current locale
        $why = cache()->remember('why_section_active_' . app()->getLocale(), 3600, function () {
            return WhySection::with(['features' => fn($q) => $q->where('is_active', true)->orderBy('sort_order')])
                ->where('is_active', true)
                ->where('locale', app()->getLocale())
                ->first();
        });

        return view('home', compact('currentTrip', 'upcomingTrip', 'recentMedia', 'socialSettings', 'heroBanners', 'why'));
    }
}
