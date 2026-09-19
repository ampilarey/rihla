<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\HeroBannerRequest;
use App\Models\HeroBanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;

class HeroBannerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', HeroBanner::class);

        // One list. A banner in a given slot used to be two rows, one per
        // language, and the homepage filtered by locale — so a slot with no
        // Dhivehi row simply vanished from /dv.
        $banners = HeroBanner::orderBy('sort_order')->get();

        return view('admin.hero-banners.index', compact('banners'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', HeroBanner::class);

        return view('admin.hero-banners.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(HeroBannerRequest $request)
    {
        $this->authorize('create', HeroBanner::class);

        $data = $request->safe()->except(['image', 'is_active']);
        $data['is_active'] = $request->has('is_active');

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->processImage($request->file('image'));
        }

        HeroBanner::create($data);

        return redirect()->route('admin.hero-banners.index')
            ->with('success', 'Hero banner created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(HeroBanner $heroBanner)
    {
        $this->authorize('view', $heroBanner);

        return view('admin.hero-banners.show', compact('heroBanner'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(HeroBanner $heroBanner)
    {
        $this->authorize('update', $heroBanner);

        return view('admin.hero-banners.edit', compact('heroBanner'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(HeroBannerRequest $request, HeroBanner $heroBanner)
    {
        $this->authorize('update', $heroBanner);

        $data = $request->safe()->except(['image', 'is_active']);
        $data['is_active'] = $request->has('is_active');

        if ($request->hasFile('image')) {
            if ($heroBanner->image_path) {
                $this->deleteImage($heroBanner->image_path);
            }

            $data['image_path'] = $this->processImage($request->file('image'));
        }

        $heroBanner->update($data);

        return redirect()->route('admin.hero-banners.index')
            ->with('success', 'Hero banner updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(HeroBanner $heroBanner)
    {
        $this->authorize('delete', $heroBanner);

        // Delete image
        if ($heroBanner->image_path) {
            $this->deleteImage($heroBanner->image_path);
        }

        $heroBanner->delete();

        return redirect()->route('admin.hero-banners.index')
            ->with('success', 'Hero banner deleted successfully.');
    }

    /**
     * Toggle banner status
     */
    public function toggleStatus(HeroBanner $heroBanner)
    {
        $this->authorize('update', $heroBanner);

        $heroBanner->update(['is_active' => ! $heroBanner->is_active]);

        return back()->with('success', 'Banner status updated successfully.');
    }

    /**
     * Update banner order
     */
    public function updateOrder(Request $request)
    {
        $this->authorize('update', HeroBanner::class);

        $request->validate([
            'banners' => 'required|array',
            'banners.*.id' => 'required|exists:hero_banners,id',
            'banners.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($request->banners as $banner) {
            HeroBanner::where('id', $banner['id'])
                ->update(['sort_order' => $banner['sort_order']]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Process uploaded image and create responsive variants
     */
    private function processImage($image): string
    {
        $filename = 'hero_'.time().'_'.uniqid();
        $extension = $image->getClientOriginalExtension();

        // Store original
        $originalPath = $image->storeAs('hero', $filename.'.'.$extension, 'public');

        // Create responsive variants
        $this->createResponsiveVariants($image, $filename);

        return $originalPath;
    }

    /**
     * Create responsive image variants
     */
    private function createResponsiveVariants($image, $filename): void
    {
        $sizes = [
            '1920w' => [1920, 1080],
            '1280w' => [1280, 720],
            '768w' => [768, 432],
        ];

        foreach ($sizes as $suffix => [$width, $height]) {
            // cover(), not contain(): a hero is a fixed frame, and letterboxing
            // it would leave bars where the photograph should be.
            Image::fromUpload($image)
                ->cover($width, $height)
                ->toWebp()
                ->quality(85)
                ->storeAs('hero', $filename.'_'.$suffix.'.webp', 'public');
        }
    }

    /**
     * Delete image and all variants
     */
    private function deleteImage($imagePath): void
    {
        $basePath = str_replace('.jpg', '', $imagePath);
        $basePath = str_replace('.png', '', $basePath);
        $basePath = str_replace('.webp', '', $basePath);

        // Delete original and variants
        $files = [
            $imagePath,
            $basePath.'_1920w.webp',
            $basePath.'_1280w.webp',
            $basePath.'_768w.webp',
        ];

        foreach ($files as $file) {
            if (Storage::disk('public')->exists($file)) {
                Storage::disk('public')->delete($file);
            }
        }
    }
}
