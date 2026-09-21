<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Learn More" is not link text — §10.1.
 *
 * Lighthouse fails the homepage on `link-text` for it, and the audit is
 * right for a reason that matters more than a score: a screen reader
 * offers its user a list of the links on a page, out of context. "Learn
 * More" in that list says nothing at all. It is also the one thing a
 * search engine has to go on about what is at the other end.
 *
 * The same button was already repointed once, from a /about that 404ed to
 * the Umrah guide. This gives it words that say so.
 *
 * Matched on the exact seeded value in each locale, so text an editor
 * chose is untouched.
 */
return new class extends Migration
{
    /** What the seeder wrote, per locale, and what it should say instead. */
    private const REPLACEMENTS = [
        'en' => ['Learn More' => 'Read the Umrah guide'],
    ];

    public function up(): void
    {
        foreach (DB::table('why_sections')->get() as $section) {
            $decoded = json_decode((string) $section->secondary_cta_text, true);

            if (! is_array($decoded)) {
                continue;
            }

            $changed = false;

            foreach (self::REPLACEMENTS as $locale => $pairs) {
                foreach ($pairs as $was => $now) {
                    if (($decoded[$locale] ?? null) === $was) {
                        $decoded[$locale] = $now;
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                DB::table('why_sections')
                    ->where('id', $section->id)
                    ->update(['secondary_cta_text' => json_encode($decoded)]);
            }
        }

        foreach (['en', 'dv'] as $locale) {
            Cache::forget("why_section_active_{$locale}");
        }

        Cache::forget('why_section_active');
    }

    /** Deliberately empty: "Learn More" is the defect, not a state to return to. */
    public function down(): void {}
};
