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
        // Derived from the title only when the form leaves it blank; the field
        // is validated for uniqueness, so an editor who sets one means it.
        $validated['slug'] = Str::slug(filled($validated['slug'] ?? null) ? $validated['slug'] : $validated['title']);
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

        // The slug is the trip's public URL. Regenerating it from the title on
        // every save meant that correcting a typo in "Ramadan Umrah 2026"
        // moved /en/trips/ramadan-umrah-2026 out from under every link that
        // had ever been shared, every bookmark, and everything indexed — and
        // it overwrote a slug the editor had deliberately set, even though the
        // form validates that field for uniqueness. It changes only when the
        // editor changes it.
        $validated['slug'] = filled($validated['slug'] ?? null)
            ? Str::slug($validated['slug'])
            : $trip->slug;

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
