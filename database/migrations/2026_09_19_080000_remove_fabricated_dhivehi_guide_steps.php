<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the machine-generated Dhivehi Umrah guide.
 *
 * `UmrahGuideSeeder` shipped ten Dhivehi steps in which every single one
 * carried the *same* `dua_text` and the same `reference_text`, while the ten
 * English steps carry ten real supplications and citations like "Quran 2:196".
 * A Dhivehi-speaking pilgrim reading the du'a for Niyyah, for Ihram, for Tawaf
 * and for Sa'i was given one identical meaningless sentence each time, under a
 * heading claiming it was the supplication for that rite.
 *
 * This is the fourth placeholder found live on this site and the only one that
 * is a religious problem rather than a commercial one.
 *
 * Deleting rather than paraphrasing is deliberate: inventing replacement
 * religious text would be a worse defect than the one being fixed. With no
 * Dhivehi steps the guide falls back to the English ones, which are correct
 * (PageController::guideStepsFor), until a translator and a scholar supply
 * real ones through the admin panel.
 *
 * Matched on the exact placeholder values the seeder wrote, never on titles or
 * locale alone, so a genuine Dhivehi step — whether it already exists or is
 * added before this runs — is never touched.
 */
return new class extends Migration
{
    private const PLACEHOLDER_DUA = 'އަޅުގަނޑުމެންނަށް ދިމާވުމަށްޓަކައިންނެވެ.';

    private const PLACEHOLDER_REFERENCE = 'އަޅުގަނޑުމެންނަށް ދިމާވުމަށްޓަކައިންނެވެ.';

    public function up(): void
    {
        DB::table('guide_steps')
            ->where('locale', 'dv')
            ->where('dua_text', self::PLACEHOLDER_DUA)
            ->where('reference_text', self::PLACEHOLDER_REFERENCE)
            ->delete();
    }

    /**
     * Deliberately empty. Putting fabricated religious text back into a live
     * Umrah guide is not a rollback anybody wants.
     */
    public function down(): void {}
};
