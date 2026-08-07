<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWhySectionRequest;
use App\Models\WhySection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class WhySectionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): RedirectResponse
    {
        $currentLocale = app()->getLocale();
        $section = WhySection::where('locale', $currentLocale)->first();
        
        if (!$section) {
            $section = WhySection::create([
                'locale' => $currentLocale,
                'title' => $currentLocale === 'dv' ? 'ރިހްލައަށް އަންނަވާނަންވާކަންތައްވަނީއެވެ؟' : 'Why Choose Rihla',
                'subtitle' => $currentLocale === 'dv' ? 'ތިޔަބޭފުޅުންނަށްޓަކައި ތިމަންމަގައިގެވިގެންވާ އަސަރުވެރިކަންތައްވަނީއެވެ' : 'Discover the unique advantages that make us your perfect travel partner',
                'is_active' => true,
            ]);
        }
        
        return redirect()->route('admin.why-sections.edit', $section);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(WhySection $section): View
    {
        $section->load('features');
        
        return view('admin.why.edit', compact('section'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWhySectionRequest $request, WhySection $section): RedirectResponse
    {
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
        
        // Clear cache for all locales
        Cache::forget('why_section_active_en');
        Cache::forget('why_section_active_dv');
        
        return redirect()->route('admin.why-sections.edit', $section)
            ->with('success', 'Why section updated successfully!');
    }
}
