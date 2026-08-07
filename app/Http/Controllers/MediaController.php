<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Setting;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function gallery(Request $request)
    {
        $query = Media::published()
            ->whereHas('trip', function ($query) {
                $query->where('status', 'past');
            })
            ->orWhereNull('trip_id');

        $type = $request->get('type');
        if ($type && in_array($type, ['photo', 'video'])) {
            $query->where('type', $type);
        }

        $media = $query->ordered()->paginate(20);
        $socialSettings = Setting::getSocialSettings();

        return view('media.gallery', compact('media', 'type', 'socialSettings'));
    }
}
