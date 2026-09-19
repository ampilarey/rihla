<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWhySectionRequest;
use App\Models\WhySection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class WhySectionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): RedirectResponse
    {
        $this->authorize('viewAny', WhySection::class);

        // One section, in both languages. This used to look up a section for
        // the *panel's* locale and create one if there was none — and the
        // Dhivehi one it created carried two hard-coded Thaana sentences
        // nobody had written. An editor opening this screen with the panel in
        // Dhivehi silently published machine-generated Dhivehi to the
        // homepage. English defaults only; the Dhivehi half is typed by a
        // person or left blank, and blank falls back to English.
        $section = WhySection::where('is_active', true)->first()
            ?? WhySection::first()
            ?? WhySection::create([
                'title' => ['en' => 'Why Choose Rihla'],
                'subtitle' => ['en' => 'Discover the unique advantages that make us your perfect travel partner'],
                'is_active' => true,
            ]);

        return redirect()->route('admin.why-sections.edit', $section);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(WhySection $section): View
    {
        $this->authorize('view', $section);

        $section->load('features');

        return view('admin.why.edit', compact('section'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWhySectionRequest $request, WhySection $section): RedirectResponse
    {
        $this->authorize('update', $section);

        $data = $request->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($section->image_path && Storage::disk('public')->exists($section->image_path)) {
                Storage::disk('public')->delete($section->image_path);
            }

            $imagePath = $request->file('image')->store('why', 'public');
            $data['image_path'] = $imagePath;
        }

        // Remove the image field from data if no new image
        unset($data['image']);

        $section->update($data);

        WhySection::forgetCache();

        return redirect()->route('admin.why-sections.edit', $section)
            ->with('success', 'Why section updated successfully!');
    }
}
