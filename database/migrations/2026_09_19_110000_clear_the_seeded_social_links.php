<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Clear the three remaining invented social links.
 *
 * `SettingsSeeder` produced four of them by typing one handle into four
 * platforms. TikTok's was provably dead and was cleared in
 * 2026_09_19_090000. Facebook and Instagram answer a login wall and Viber
 * answers a generic page for any path, so no script could confirm the other
 * three — the owner has now confirmed they were guesses and asked for them to
 * go.
 *
 * Matched on the exact seeded values, so anything entered by hand survives.
 * The social page hides a link that is not set, so this leaves no empty
 * anchor: the section simply shows the links that are real, which for now
 * means WhatsApp.
 */
return new class extends Migration
{
    private const SEEDED = [
        'facebook_url' => 'https://facebook.com/rihlatravels',
        'instagram_url' => 'https://instagram.com/rihlatravels',
        'viber_url' => 'https://viber.com/rihlatravels',
    ];

    public function up(): void
    {
        $social = Setting::getSocialSettings();
        $changed = false;

        foreach (self::SEEDED as $key => $guess) {
            if (($social[$key] ?? null) === $guess) {
                $social[$key] = null;
                $changed = true;
            }
        }

        if ($changed) {
            Setting::setSocialSettings($social);
        }
    }

    /** Deliberately empty: restoring an unverified link is not a rollback. */
    public function down(): void {}
};
