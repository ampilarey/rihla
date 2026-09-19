<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The homepage's "Learn More" button pointed at /about, which does not exist.
 *
 * `WhySectionSeeder` wrote it, no route ever answered it, and it sat on the
 * most-visited page as a 404 for anyone who clicked. The Umrah guide is what
 * "learn more" should mean on this site.
 *
 * Matched on the exact seeded value, so a URL an editor chose is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('why_sections')->where('secondary_cta_url', '/about')->update(['secondary_cta_url' => '/guide']);

        foreach (['en', 'dv'] as $locale) {
            Cache::forget("why_section_active_{$locale}");
        }
    }

    /** Deliberately empty: nothing lives at /about to point back to. */
    public function down(): void {}
};
