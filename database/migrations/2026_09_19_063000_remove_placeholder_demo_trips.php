<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delete the demo trips that were live on the public test site.
 *
 * TripSeeder stopped creating these, but a seeder change only governs the next
 * seed — deploys run `migrate --force`, not `db:seed`. The same gap left the
 * placeholder media on the page after its seeder was cleaned, so this ships
 * alongside the seeder change rather than after it.
 *
 * Each was on its own indexed URL under Rihla's branding: an Umrah operator
 * advertising "Luxury Resort Experience — overwater villas, perfect for
 * honeymooners and luxury travelers".
 *
 * Rows are matched on the exact slugs the seeder wrote, never on title, so a
 * real trip that happens to share a name is untouched. Production never ran
 * the demo seeder, so this finds nothing there.
 */
return new class extends Migration
{
    /** Exactly what TripSeeder used to insert. */
    private const PLACEHOLDER_SLUGS = [
        'maldives-island-hopping-adventure',
        'luxury-resort-experience',
        'cultural-heritage-tour',
    ];

    public function up(): void
    {
        $ids = DB::table('trips')->whereIn('slug', self::PLACEHOLDER_SLUGS)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Media hangs off a trip by foreign key; clear the link rather than
        // leaving rows pointing at a trip that no longer exists.
        DB::table('media')->whereIn('trip_id', $ids)->update(['trip_id' => null]);

        DB::table('trips')->whereIn('id', $ids)->delete();
    }

    /**
     * Deliberately irreversible.
     *
     * Restoring these would put a honeymoon resort package back on a
     * pilgrimage site. There is nothing here worth being able to undo.
     */
    public function down(): void
    {
        //
    }
};
