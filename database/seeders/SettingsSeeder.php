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
            // The four social URLs are deliberately empty.
            //
            // They used to be seeded as facebook.com/rihlatravels,
            // instagram.com/rihlatravels, tiktok.com/@rihlatravels and
            // viber.com/rihlatravels — one handle typed into four platforms
            // and never checked. TikTok answers "Page not available", so that
            // account does not exist; Facebook and Instagram sit behind login
            // walls and Viber returns a generic account page, so no automated
            // check can confirm the other three either. Only the owner knows.
            //
            // A dead social link on a travel operator's site costs more than a
            // missing one, and the social page already hides each link that is
            // not set — the same reason youtube_playlist_id below is empty.
            // `rihla:preflight` warns if any of them reappears as a seeded
            // guess. Set the real ones in Admin → Settings.
            'facebook_url' => null,
            'instagram_url' => null,
            'tiktok_url' => null,
            'viber_url' => null,

            // Not a guess: this is the business's real number, and the single
            // source for it is App\Support\Contact.
            'whatsapp_number' => '9607972434',
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
