<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Remove the TikTok link, which points at an account that does not exist.
 *
 * `SettingsSeeder` invented four social URLs by typing one handle into four
 * platforms: facebook.com/rihlatravels, instagram.com/rihlatravels,
 * tiktok.com/@rihlatravels and viber.com/rihlatravels. None was ever checked.
 *
 * TikTok answers "Page not available" for that handle, so the public social
 * page has been sending visitors to a dead profile. That one is provable, so
 * it is cleared here. Facebook and Instagram sit behind login walls and Viber
 * returns a generic account page for any path, so no automated check can say
 * whether those three are real — only the owner can, and clearing a link that
 * works would be its own defect. They are left alone, and `rihla:preflight`
 * now warns while they still match the seeded guess.
 *
 * Matched on the exact seeded value, so an owner-entered TikTok URL is never
 * touched. The social page hides links that are not set, so this leaves no
 * gap, in the same way the placeholder YouTube playlist did.
 */
return new class extends Migration
{
    private const SEEDED_TIKTOK = 'https://tiktok.com/@rihlatravels';

    public function up(): void
    {
        $social = Setting::getSocialSettings();

        if (($social['tiktok_url'] ?? null) !== self::SEEDED_TIKTOK) {
            return;
        }

        Setting::setSocialSettings(array_merge($social, ['tiktok_url' => null]));
    }

    /** Deliberately empty: putting a dead link back is not a rollback. */
    public function down(): void {}
};
