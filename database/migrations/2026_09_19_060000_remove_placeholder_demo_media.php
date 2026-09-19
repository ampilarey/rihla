<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delete the demo media that was live on the public test site.
 *
 * MediaSeeder stopped creating these, but a seeder change only governs the
 * next seed — deploys run `migrate --force`, not `db:seed`, so the rows it
 * made were still in test.rihla.mv's database and still on the page. The two
 * videos were `dQw4w9WgXcQ` and `9bZkp7q19f0`, titled "Cultural Heritage Tour
 * Highlights" and "Local Market Experience": Rick Astley and Gangnam Style,
 * on an Umrah operator's gallery, under its branding.
 *
 * Rows are matched on the exact values the seeder wrote — the two video URLs
 * and the two file paths — and never on title alone, so a real row that
 * happens to share a name is untouched. Production never ran the demo seeder,
 * so this finds nothing there.
 */
return new class extends Migration
{
    /** Exactly what MediaSeeder used to insert. */
    private const PLACEHOLDER_VIDEOS = [
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=9bZkp7q19f0',
    ];

    private const PLACEHOLDER_FILES = [
        'media/sunset.jpg',
        'media/waters.jpg',
    ];

    public function up(): void
    {
        DB::table('media')->whereIn('video_url', self::PLACEHOLDER_VIDEOS)->delete();
        DB::table('media')->whereIn('file_path', self::PLACEHOLDER_FILES)->delete();
    }

    /**
     * Deliberately irreversible.
     *
     * Restoring these would put two pop videos back on a pilgrimage site.
     * There is nothing here worth being able to undo.
     */
    public function down(): void
    {
        //
    }
};
