<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TripRequest;
use App\Models\Trip;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TripController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Trip::class);

        $locale = app()->getLocale();
        $trips = Trip::where('locale', $locale)->orderBy('created_at', 'desc')->paginate(20);

        return view('admin.trips.index', compact('trips', 'locale'));
    }

    public function create()
    {
        $this->authorize('create', Trip::class);

        $locale = app()->getLocale();

        return view('admin.trips.create', compact('locale'));
    }

    public function store(TripRequest $request)
    {
        $this->authorize('create', Trip::class);

        $validated = $request->validated();
        $validated['slug'] = Str::slug($validated['title']);
        $validated['is_published'] = $request->has('is_published');
        $validated['locale'] = app()->getLocale();

        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store('trips', 'public');
            $validated['cover_image'] = $path;
        }

        Trip::create($validated);

        return redirect()->route('admin.trips.index')->with('success', 'Trip created successfully.');
    }

    public function show(Trip $trip)
    {
        $this->authorize('view', $trip);

        $locale = app()->getLocale();

        return view('admin.trips.show', compact('trip', 'locale'));
    }

    public function edit(Trip $trip)
    {
        $this->authorize('update', $trip);

        $locale = app()->getLocale();

        return view('admin.trips.edit', compact('trip', 'locale'));
    }

    public function update(TripRequest $request, Trip $trip)
    {
        $this->authorize('update', $trip);

        $validated = $request->validated();
        $validated['slug'] = Str::slug($validated['title']);
        $validated['is_published'] = $request->has('is_published');

        if ($request->hasFile('cover_image')) {
            // Delete old image
            if ($trip->cover_image) {
                Storage::disk('public')->delete($trip->cover_image);
            }
            $path = $request->file('cover_image')->store('trips', 'public');
            $validated['cover_image'] = $path;
        }

        $trip->update($validated);

        return redirect()->route('admin.trips.index')->with('success', 'Trip updated successfully.');
    }

    public function destroy(Trip $trip)
    {
        $this->authorize('delete', $trip);

        if ($trip->cover_image) {
            Storage::disk('public')->delete($trip->cover_image);
        }

        $trip->delete();

        return redirect()->route('admin.trips.index')->with('success', 'Trip deleted successfully.');
    }
}
