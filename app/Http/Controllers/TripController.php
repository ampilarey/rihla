<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Trip;

class TripController extends Controller
{
    public function index()
    {
        $currentTrips = Trip::published()->current()->orderBy('date_start')->get();
        $upcomingTrips = Trip::published()->upcoming()->orderBy('date_start')->paginate(6);
        $pastTrips = Trip::published()->past()->orderBy('date_end', 'desc')->paginate(12);

        $socialSettings = Setting::getSocialSettings();

        return view('trips.index', compact('currentTrips', 'upcomingTrips', 'pastTrips', 'socialSettings'));
    }

    public function show($slug)
    {
        $trip = Trip::published()->where('slug', $slug)->firstOrFail();
        $media = $trip->media()->published()->ordered()->get();

        $socialSettings = Setting::getSocialSettings();

        return view('trips.show', compact('trip', 'media', 'socialSettings'));
    }
}
