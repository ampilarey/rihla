<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Clear the placeholder YouTube playlist id.
 *
 * `SettingsSeeder` seeded `PLxxxxxxxxxx` with a "replace with actual playlist
 * ID" comment that was never acted on, so the social page embedded a player
 * pointed at a playlist that does not exist — a 404 inside an iframe, where
 * the videos should be. It was live on test.rihla.mv.
 *
 * A seeder change alone would not have reached it: deploys run
 * `migrate --force`, not `db:seed`. That is the third time in this branch, so
 * the cleanup ships with the seeder change rather than after it.
 *
 * Only the exact placeholder is cleared. A real id someone has since set is
 * left alone.
 */
return new class extends Migration
{
    private const PLACEHOLDER = 'PLxxxxxxxxxx';

    public function up(): void
    {
        $social = Setting::getSocialSettings();

        if (($social['youtube_playlist_id'] ?? null) !== self::PLACEHOLDER) {
            return;
        }

        Setting::setSocialSettings(['youtube_playlist_id' => null] + $social);
    }

    /** Deliberately irreversible: there is nothing worth restoring. */
    public function down(): void
    {
        //
    }
};
