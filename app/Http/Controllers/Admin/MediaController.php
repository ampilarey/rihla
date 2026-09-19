<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\MediaRequest;
use App\Models\Media;
use App\Models\Trip;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function store(MediaRequest $request)
    {
        $this->authorize('create', Media::class);

        $validated = $request->validated();
        $validated['is_published'] = $request->has('is_published');
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        if ($request->type === 'photo' && $request->hasFile('file_path')) {
            $file = $request->file('file_path');

            // The client's own filename is not used: it is attacker-supplied,
            // and time() alone collides for two uploads in the same second.
            $name = now()->format('Ymd_His').'_'.Str::random(8);

            $file->store('media/original', 'public');

            Image::fromUpload($file)
                ->scale(width: 1600)
                ->toWebp()
                ->quality(80)
                ->storeAs('media/large', $name.'.webp', 'public');

            Image::fromUpload($file)
                ->scale(width: 400)
                ->toWebp()
                ->quality(80)
                ->storeAs('media/thumbs', $name.'.webp', 'public');

            $validated['file_path'] = 'media/large/'.$name.'.webp';
            $validated['thumb_path'] = 'media/thumbs/'.$name.'.webp';
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

    public function update(MediaRequest $request, Media $medium)
    {
        $this->authorize('update', $medium);

        $validated = $request->validated();
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

            // The client's own filename is not used: it is attacker-supplied,
            // and time() alone collides for two uploads in the same second.
            $name = now()->format('Ymd_His').'_'.Str::random(8);

            $file->store('media/original', 'public');

            Image::fromUpload($file)
                ->scale(width: 1600)
                ->toWebp()
                ->quality(80)
                ->storeAs('media/large', $name.'.webp', 'public');

            Image::fromUpload($file)
                ->scale(width: 400)
                ->toWebp()
                ->quality(80)
                ->storeAs('media/thumbs', $name.'.webp', 'public');

            $validated['file_path'] = 'media/large/'.$name.'.webp';
            $validated['thumb_path'] = 'media/thumbs/'.$name.'.webp';
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
