<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class MediaController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Media::class);

        $media = Media::with('trip')->orderBy('created_at', 'desc')->paginate(20);

        return view('admin.media.index', compact('media'));
    }

    public function create()
    {
        $this->authorize('create', Media::class);

        $trips = Trip::orderBy('title')->get();

        return view('admin.media.create', compact('trips'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Media::class);

        $validated = $request->validate([
            'trip_id' => 'nullable|exists:trips,id',
            'type' => 'required|in:photo,video',
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string',
            'file_path' => 'required_if:type,photo|nullable|image|mimes:jpeg,png,webp|max:6144',
            'video_url' => 'required_if:type,video|nullable|url',
            'sort_order' => 'nullable|integer|min:0',
            'is_published' => 'boolean',
        ]);

        $validated['is_published'] = $request->has('is_published');
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        if ($request->type === 'photo' && $request->hasFile('file_path')) {
            $file = $request->file('file_path');
            $filename = time().'_'.$file->getClientOriginalName();

            // Store original file
            $originalPath = $file->store('media/original', 'public');

            // Create large version (1600px max width) as WebP
            $largeImage = Image::make($file);
            $largeImage->resize(1600, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            $largePath = 'media/large/'.pathinfo($filename, PATHINFO_FILENAME).'.webp';
            Storage::disk('public')->put($largePath, $largeImage->encode('webp', 80));

            // Create thumbnail (400px width) as WebP
            $thumbImage = Image::make($file);
            $thumbImage->resize(400, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            $thumbPath = 'media/thumbs/'.pathinfo($filename, PATHINFO_FILENAME).'.webp';
            Storage::disk('public')->put($thumbPath, $thumbImage->encode('webp', 80));

            $validated['file_path'] = $largePath;
            $validated['thumb_path'] = $thumbPath;
        }

        Media::create($validated);

        return redirect()->route('admin.media.index')->with('success', 'Media created successfully.');
    }

    public function show(Media $medium)
    {
        $this->authorize('view', $medium);

        return view('admin.media.show', compact('medium'));
    }

    public function edit(Media $medium)
    {
        $this->authorize('update', $medium);

        $trips = Trip::orderBy('title')->get();

        return view('admin.media.edit', compact('medium', 'trips'));
    }

    public function update(Request $request, Media $medium)
    {
        $this->authorize('update', $medium);

        $validated = $request->validate([
            'trip_id' => 'nullable|exists:trips,id',
            'type' => 'required|in:photo,video',
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string',
            'file_path' => 'nullable|image|mimes:jpeg,png,webp|max:6144',
            'video_url' => 'nullable|url',
            'sort_order' => 'nullable|integer|min:0',
            'is_published' => 'boolean',
        ]);

        $validated['is_published'] = $request->has('is_published');

        if ($request->type === 'photo' && $request->hasFile('file_path')) {
            // Delete old files
            if ($medium->file_path) {
                Storage::disk('public')->delete($medium->file_path);
            }
            if ($medium->thumb_path) {
                Storage::disk('public')->delete($medium->thumb_path);
            }

            $file = $request->file('file_path');
            $filename = time().'_'.$file->getClientOriginalName();

            // Store original file
            $originalPath = $file->store('media/original', 'public');

            // Create large version (1600px max width) as WebP
            $largeImage = Image::make($file);
            $largeImage->resize(1600, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            $largePath = 'media/large/'.pathinfo($filename, PATHINFO_FILENAME).'.webp';
            Storage::disk('public')->put($largePath, $largeImage->encode('webp', 80));

            // Create thumbnail (400px width) as WebP
            $thumbImage = Image::make($file);
            $thumbImage->resize(400, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            $thumbPath = 'media/thumbs/'.pathinfo($filename, PATHINFO_FILENAME).'.webp';
            Storage::disk('public')->put($thumbPath, $thumbImage->encode('webp', 80));

            $validated['file_path'] = $largePath;
            $validated['thumb_path'] = $thumbPath;
        }

        $medium->update($validated);

        return redirect()->route('admin.media.index')->with('success', 'Media updated successfully.');
    }

    public function destroy(Media $medium)
    {
        $this->authorize('delete', $medium);

        try {
            // Log the deletion attempt

            // Delete files if they exist
            if ($medium->file_path) {
                if (Storage::disk('public')->exists($medium->file_path)) {
                    Storage::disk('public')->delete($medium->file_path);
                }
            }

            if ($medium->thumb_path) {
                if (Storage::disk('public')->exists($medium->thumb_path)) {
                    Storage::disk('public')->delete($medium->thumb_path);
                }
            }

            $medium->delete();

            return redirect()->route('admin.media.index')->with('success', 'Media deleted successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to delete media', [
                'id' => $medium->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('admin.media.index')->with('error', 'Failed to delete media: '.$e->getMessage());
        }
    }
}
