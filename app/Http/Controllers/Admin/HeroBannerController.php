<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HeroBanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Facades\Image;

class HeroBannerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', HeroBanner::class);

        $banners = HeroBanner::orderBy('locale')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('locale');

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
    public function store(Request $request)
    {
        $this->authorize('create', HeroBanner::class);

        $validator = Validator::make($request->all(), [
            'locale' => 'required|in:en,dv',
            'title' => 'required|string|max:120',
            'subtitle' => 'nullable|string|max:200',
            'primary_cta_text' => 'nullable|string|max:60',
            'primary_cta_url' => 'nullable|string|max:255',
            'secondary_cta_text' => 'nullable|string|max:60',
            'secondary_cta_url' => 'nullable|string|max:255',
            'overlay_opacity' => 'integer|between:0,100',
            'heading_color' => 'nullable|string|max:20',
            'heading_size' => 'nullable|string|max:20',
            'heading_weight' => 'nullable|string|max:20',
            'subheading_color' => 'nullable|string|max:20',
            'subheading_size' => 'nullable|string|max:20',
            'subheading_weight' => 'nullable|string|max:20',
            'primary_cta_bg_color' => 'nullable|string|max:20',
            'primary_cta_text_color' => 'nullable|string|max:20',
            'primary_cta_size' => 'nullable|string|max:20',
            'primary_cta_radius' => 'nullable|string|max:20',
            'secondary_cta_bg_color' => 'nullable|string|max:20',
            'secondary_cta_text_color' => 'nullable|string|max:20',
            'secondary_cta_size' => 'nullable|string|max:20',
            'secondary_cta_radius' => 'nullable|string|max:20',
            'sort_order' => 'integer|min:0',
            'is_active' => 'nullable', // Changed from 'boolean' to 'nullable'
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after:start_at',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:8192',
        ]);

        if ($validator->fails()) {
            \Log::warning('HeroBanner validation failed', [
                'errors' => $validator->errors()->toArray(),
            ]);

            return back()->withErrors($validator)->withInput();
        }

        try {
            $data = $validator->validated();
            $data['is_active'] = $request->has('is_active');

            // Debug: Log the validated data

            // Handle image upload
            if ($request->hasFile('image')) {
                $imagePath = $this->processImage($request->file('image'));
                $data['image_path'] = $imagePath;
            }

            // Create the banner
            $banner = HeroBanner::create($data);

            return redirect()->route('admin.hero-banners.index')
                ->with('success', 'Hero banner created successfully.');
        } catch (\Exception $e) {
            \Log::error('HeroBanner creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withErrors(['error' => 'Failed to create banner: '.$e->getMessage()])->withInput();
        }
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
    public function update(Request $request, HeroBanner $heroBanner)
    {
        $this->authorize('update', $heroBanner);

        $validator = Validator::make($request->all(), [
            'locale' => 'required|in:en,dv',
            'title' => 'required|string|max:120',
            'subtitle' => 'nullable|string|max:200',
            'primary_cta_text' => 'nullable|string|max:60',
            'primary_cta_url' => 'nullable|string|max:255',
            'secondary_cta_text' => 'nullable|string|max:60',
            'secondary_cta_url' => 'nullable|string|max:255',
            'overlay_opacity' => 'integer|between:0,100',
            'heading_color' => 'nullable|string|max:20',
            'heading_size' => 'nullable|string|max:20',
            'heading_weight' => 'nullable|string|max:20',
            'subheading_color' => 'nullable|string|max:20',
            'subheading_size' => 'nullable|string|max:20',
            'subheading_weight' => 'nullable|string|max:20',
            'primary_cta_bg_color' => 'nullable|string|max:20',
            'primary_cta_text_color' => 'nullable|string|max:20',
            'primary_cta_size' => 'nullable|string|max:20',
            'primary_cta_radius' => 'nullable|string|max:20',
            'secondary_cta_bg_color' => 'nullable|string|max:20',
            'secondary_cta_text_color' => 'nullable|string|max:20',
            'secondary_cta_size' => 'nullable|string|max:20',
            'secondary_cta_radius' => 'nullable|string|max:20',
            'sort_order' => 'integer|min:0',
            'is_active' => 'nullable', // Changed from 'boolean' to 'nullable'
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after:start_at',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:8192',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $data['is_active'] = $request->has('is_active');

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image
            if ($heroBanner->image_path) {
                $this->deleteImage($heroBanner->image_path);
            }

            $imagePath = $this->processImage($request->file('image'));
            $data['image_path'] = $imagePath;
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
            $variant = Image::make($image)
                ->fit($width, $height, function ($constraint) {
                    $constraint->upsize();
                })
                ->encode('webp', 85);

            Storage::disk('public')->put(
                'hero/'.$filename.'_'.$suffix.'.webp',
                $variant
            );
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
