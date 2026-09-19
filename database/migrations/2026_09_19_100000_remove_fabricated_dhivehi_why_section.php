<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Remove the machine-generated Dhivehi "Why Choose Rihla" section.
 *
 * Same signature as the guide steps and the language files: distinct English
 * strings rendered as near-identical Dhivehi ones. The three feature titles are
 * 24-26 characters long and share a 19-character suffix. The three bodies are
 * worse — two of them share 72 of their 77 characters — where the English
 * copy for the same three points ("Trusted Guides", "Comfort Stays", "Clear
 * Pricing") shares 2 and 12 characters, which is ordinary incidental overlap.
 *
 * This one is on the homepage, so it is the fabricated Dhivehi a visitor was
 * most likely to see.
 *
 * Deleted rather than paraphrased, and matched on the exact seeded titles so
 * anything a person wrote is untouched. `HomeController` now falls back to the
 * English section for a locale that has none, so `/dv` keeps the block.
 */
return new class extends Migration
{
    private const SEEDED_TITLE = 'ރިހްލައަށް އަންނަވާނަންވާކަންތައްވަނީއެވެ؟';

    /** @var list<string> */
    private const SEEDED_FEATURES = [
        'ވިސްވަރަށްޓަކައިވާލުންތައް',
        'ރައްޓަށްޓަކައިވާލުންތައް',
        'ސަފުވަށްޓަކައިވާލުންތައް',
    ];

    public function up(): void
    {
        $sections = DB::table('why_sections')
            ->where('locale', 'dv')
            ->where('title', self::SEEDED_TITLE)
            ->pluck('id');

        if ($sections->isEmpty()) {
            return;
        }

        DB::table('why_features')
            ->whereIn('why_section_id', $sections)
            ->whereIn('title', self::SEEDED_FEATURES)
            ->delete();

        DB::table('why_sections')->whereIn('id', $sections)->delete();

        // HomeController caches this per locale for an hour. Without this the
        // homepage would keep serving the deleted section until it expired —
        // or indefinitely on a driver that survives a deploy.
        foreach (['en', 'dv'] as $locale) {
            Cache::forget("why_section_active_{$locale}");
        }
    }

    /** Deliberately empty. */
    public function down(): void {}
};
