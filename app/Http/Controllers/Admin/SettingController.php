<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $this->authorize('view', Setting::class);

        $socialSettings = Setting::getSocialSettings();

        return view('admin.settings.index', compact('socialSettings'));
    }

    public function update(Request $request)
    {
        $this->authorize('update', Setting::class);

        $validated = $request->validate([
            'facebook_url' => 'nullable|url',
            'instagram_url' => 'nullable|url',
            'tiktok_url' => 'nullable|url',
            'whatsapp_number' => 'required|string|max:20',
            'viber_url' => 'nullable|url',
            'youtube_playlist_id' => 'nullable|string|max:50',
        ]);

        Setting::setSocialSettings($validated);

        return redirect()->route('admin.settings.index')->with('success', 'Settings updated successfully.');
    }
}
