<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GuideStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class GuideStepController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', GuideStep::class);

        $guideSteps = GuideStep::orderBy('locale')
            ->orderBy('step_number')
            ->get()
            ->groupBy('locale');

        return view('admin.guide-steps.index', compact('guideSteps'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', GuideStep::class);

        return view('admin.guide-steps.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * The textarea sends one note per line; the column is a JSON array.
     *
     * Without this the `array` rule rejects everything an editor types into
     * the form, and without the rule the raw string would be stored where the
     * guide expects a list. An array is accepted unchanged, so a test or a
     * future API can post one directly.
     *
     * @return list<string>
     */
    private function normaliseFiqhNotes(mixed $notes): array
    {
        if (is_array($notes)) {
            return array_values(array_filter(array_map('trim', $notes), 'strlen'));
        }

        if (! is_string($notes)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $notes) ?: []),
            'strlen',
        ));
    }

    public function store(Request $request)
    {
        $this->authorize('create', GuideStep::class);

        $request->merge(['fiqh_notes' => $this->normaliseFiqhNotes($request->input('fiqh_notes'))]);

        $request->validate([
            'step_number' => 'required|integer|min:1',
            'locale' => 'required|in:en,dv',
            'title' => 'required|string|max:120',
            'summary' => 'required|string',
            'details' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:6144',
            'dua_text' => 'nullable|string',
            'reference_text' => 'nullable|string|max:500',
            'fiqh_notes' => 'nullable|array',
            'fiqh_notes.*' => 'string|max:500',
            'video_url' => 'nullable|url|max:255',
            'checklist' => 'nullable|array',
            'checklist.*' => 'string|max:255',
            'is_published' => 'boolean',
        ]);

        $data = $request->only([
            'step_number', 'locale', 'title', 'summary', 'details',
            'dua_text', 'reference_text', 'fiqh_notes', 'video_url', 'is_published',
        ]);

        // Process checklist and fiqh_notes
        if ($request->has('checklist')) {
            $data['checklist'] = array_filter($request->input('checklist', []));
        }

        $data['fiqh_notes'] = $request->input('fiqh_notes', []);

        $data['is_published'] = $request->has('is_published');

        if ($request->hasFile('image')) {
            $imagePath = $this->processImage($request->file('image'));
            $data['image_path'] = $imagePath;
        }

        GuideStep::create($data);

        return redirect()->route('admin.guide-steps.index')
            ->with('success', 'Guide step created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(GuideStep $guideStep)
    {
        $this->authorize('view', $guideStep);

        // There is no admin.guide-steps.show view and never has been, so this
        // returned "View [admin.guide-steps.show] not found" — a 500 on every
        // attempt to open a step. Editing is what the panel is for, and the
        // edit screen already shows everything a read-only one would.
        return redirect()->route('admin.guide-steps.edit', $guideStep);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(GuideStep $guideStep)
    {
        $this->authorize('update', $guideStep);

        return view('admin.guide-steps.edit', compact('guideStep'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, GuideStep $guideStep)
    {
        $this->authorize('update', $guideStep);

        $request->merge(['fiqh_notes' => $this->normaliseFiqhNotes($request->input('fiqh_notes'))]);

        $request->validate([
            'step_number' => 'required|integer|min:1',
            'locale' => 'required|in:en,dv',
            'title' => 'required|string|max:120',
            'summary' => 'required|string',
            'details' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:6144',
            'dua_text' => 'nullable|string',
            'reference_text' => 'nullable|string|max:500',
            'fiqh_notes' => 'nullable|array',
            'fiqh_notes.*' => 'string|max:500',
            'video_url' => 'nullable|url|max:255',
            'checklist' => 'nullable|array',
            'checklist.*' => 'string|max:255',
            'is_published' => 'boolean',
        ]);

        $data = $request->only([
            'step_number', 'locale', 'title', 'summary', 'details',
            'dua_text', 'reference_text', 'fiqh_notes', 'video_url', 'is_published',
        ]);

        // Process checklist and fiqh_notes
        if ($request->has('checklist')) {
            $data['checklist'] = array_filter($request->input('checklist', []));
        }

        $data['fiqh_notes'] = $request->input('fiqh_notes', []);

        $data['is_published'] = $request->has('is_published');

        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($guideStep->image_path) {
                $this->deleteImage($guideStep->image_path);
            }

            $imagePath = $this->processImage($request->file('image'));
            $data['image_path'] = $imagePath;
        }

        $guideStep->update($data);

        return redirect()->route('admin.guide-steps.index')
            ->with('success', 'Guide step updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(GuideStep $guideStep)
    {
        $this->authorize('delete', $guideStep);

        if ($guideStep->image_path) {
            $this->deleteImage($guideStep->image_path);
        }

        $guideStep->delete();

        return redirect()->route('admin.guide-steps.index')
            ->with('success', 'Guide step deleted successfully.');
    }

    /**
     * Update the order of guide steps
     */
    public function updateOrder(Request $request)
    {
        $this->authorize('update', GuideStep::class);

        $request->validate([
            'steps' => 'required|array',
            'steps.*.id' => 'required|exists:guide_steps,id',
            'steps.*.step_number' => 'required|integer|min:1',
        ]);

        foreach ($request->input('steps') as $step) {
            GuideStep::where('id', $step['id'])->update(['step_number' => $step['step_number']]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Toggle publish status of a guide step
     */
    public function toggleStatus(GuideStep $guideStep)
    {
        $this->authorize('update', $guideStep);

        $guideStep->update(['is_published' => ! $guideStep->is_published]);

        return response()->json([
            'success' => true,
            'is_published' => $guideStep->is_published,
        ]);
    }

    /**
     * Bulk publish/unpublish guide steps
     */
    public function bulkUpdateStatus(Request $request)
    {
        $this->authorize('update', GuideStep::class);

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:guide_steps,id',
            'status' => 'required|boolean',
        ]);

        GuideStep::whereIn('id', $request->input('ids'))
            ->update(['is_published' => $request->input('status')]);

        $action = $request->input('status') ? 'published' : 'unpublished';

        return redirect()->route('admin.guide-steps.index')
            ->with('success', "Selected guide steps {$action} successfully.");
    }

    /**
     * Process uploaded image
     */
    private function processImage($image)
    {
        $filename = 'step_'.time().'.webp';
        $path = 'guide/'.$filename;

        // Create guide directory if it doesn't exist
        if (! Storage::disk('public')->exists('guide')) {
            Storage::disk('public')->makeDirectory('guide');
        }

        // Process and save large image (max 1200px wide)
        $img = Image::make($image);
        if ($img->width() > 1200) {
            $img->resize(1200, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }
        $img->encode('webp', 85);
        Storage::disk('public')->put($path, $img);

        // Create thumbnail (400px wide)
        $thumbnail = Image::make($image);
        if ($thumbnail->width() > 400) {
            $thumbnail->resize(400, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }
        $thumbnail->encode('webp', 85);

        $thumbnailPath = 'guide/'.pathinfo($filename, PATHINFO_FILENAME).'-thumb.webp';
        Storage::disk('public')->put($thumbnailPath, $thumbnail);

        return $path;
    }

    /**
     * Delete image and thumbnail
     */
    private function deleteImage($imagePath)
    {
        if (Storage::disk('public')->exists($imagePath)) {
            Storage::disk('public')->delete($imagePath);
        }

        // Delete thumbnail
        $path = pathinfo($imagePath, PATHINFO_DIRNAME);
        $filename = pathinfo($imagePath, PATHINFO_FILENAME);
        $thumbnailPath = $path.'/'.$filename.'-thumb.webp';

        if (Storage::disk('public')->exists($thumbnailPath)) {
            Storage::disk('public')->delete($thumbnailPath);
        }
    }
}
