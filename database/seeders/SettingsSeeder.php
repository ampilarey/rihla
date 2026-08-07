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
            'youtube_playlist_id' => 'PLxxxxxxxxxx', // Replace with actual playlist ID
        ]);
    }
}
