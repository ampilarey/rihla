<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::setSocialSettings([
            'facebook_url' => 'https://facebook.com/rihlatravels',
            'instagram_url' => 'https://instagram.com/rihlatravels',
            'tiktok_url' => 'https://tiktok.com/@rihlatravels',
            'whatsapp_number' => '9607972434',
            'viber_url' => 'https://viber.com/rihlatravels',
            // Left empty on purpose. It used to seed PLxxxxxxxxxx, carrying a
            // "replace with actual playlist ID" comment that nobody replaced —
            // so the social page embedded a YouTube player pointed at a
            // playlist that does not exist, and visitors got an error where
            // the videos should be. Unlike the trip and media seeders, this
            // one has no production guard, because it seeds real configuration
            // rather than demo content; a placeholder here reaches the live
            // site.
            //
            // The social page hides the whole "Our Videos" section when this
            // is empty, so an absent playlist shows nothing rather than a
            // broken embed. Set the real id in Admin → Settings.
            'youtube_playlist_id' => null,
        ]);
    }
}
