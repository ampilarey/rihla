<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertWhyFeatureRequest;
use App\Models\WhyFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class WhyFeatureController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(UpsertWhyFeatureRequest $request): RedirectResponse
    {
        $this->authorize('create', WhyFeature::class);

        $data = $request->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('why/features', 'public');
            $data['image_path'] = $imagePath;
        }

        // Remove the image field from data if no new image
        unset($data['image']);

        WhyFeature::create($data);

        // Clear cache for all locales
        Cache::forget('why_section_active_en');
        Cache::forget('why_section_active_dv');

        return redirect()->back()->with('success', 'Feature created successfully!');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(WhyFeature $feature): View
    {
        $this->authorize('view', $feature);

        return view('admin.why.feature_edit', compact('feature'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpsertWhyFeatureRequest $request, WhyFeature $feature): RedirectResponse
    {
        $this->authorize('update', $feature);

        $data = $request->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($feature->image_path && Storage::disk('public')->exists($feature->image_path)) {
                Storage::disk('public')->delete($feature->image_path);
            }

            $imagePath = $request->file('image')->store('why/features', 'public');
            $data['image_path'] = $imagePath;
        }

        // Remove the image field from data if no new image
        unset($data['image']);

        $feature->update($data);

        // Clear cache for all locales
        Cache::forget('why_section_active_en');
        Cache::forget('why_section_active_dv');

        return redirect()->route('admin.why-sections.edit', $feature->section)
            ->with('success', 'Feature updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WhyFeature $feature): RedirectResponse
    {
        $this->authorize('delete', $feature);

        // Delete image if exists
        if ($feature->image_path && Storage::disk('public')->exists($feature->image_path)) {
            Storage::disk('public')->delete($feature->image_path);
        }

        $section = $feature->section;
        $feature->delete();

        // Clear cache for all locales
        Cache::forget('why_section_active_en');
        Cache::forget('why_section_active_dv');

        return redirect()->route('admin.why-sections.edit', $section)
            ->with('success', 'Feature deleted successfully!');
    }
}
