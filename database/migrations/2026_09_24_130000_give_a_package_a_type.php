<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A package says what kind of thing it is — §15.3 (Phase 8.7).
 *
 * The owner's correction, recorded in §15.1 so it stays corrected:
 * *"Keep holiday packages with Umrah under Umrah services, and holiday
 * packages for locals to local islands in the guesthouses part."* An Umrah
 * with a Turkey extension is an Umrah product; a Fulidhoo weekend for a
 * Malé family is a guesthouse product. They are not one tab called
 * "Holidays", and this column is what keeps them apart.
 *
 * `island_holiday` is listed here but sells nothing yet — Phase 10 builds
 * it on this same engine. The type exists now so that phase is a content
 * task rather than another migration against a table with live bookings
 * hanging off it.
 *
 * Every existing row is an Umrah, which is what the default records. A
 * literal, not `Package::UMRAH`: a migration says what happened on the day
 * it ran, and a constant moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->string('type', 20)->default('umrah')->after('slug');

            // The extension segment, and the only thing an Umrah Plus adds
            // to an Umrah. Nullable because the other two types have none —
            // the package page shows this block or it does not.
            $table->string('extension_destination', 120)->nullable()->after('nights');
            $table->unsignedSmallInteger('extension_nights')->nullable()->after('extension_destination');
            $table->json('extension_details')->nullable()->after('extension_nights');

            // The public list filters on this, and the admin groups by it.
            $table->index(['type', 'is_published']);
        });
    }

    public function down(): void
    {
        // The index goes before the column it names: SQLite rebuilds the
        // table on `dropColumn` and fails on anything still mentioning a
        // departing column. Same lesson as Phase 8.6, one migration later.
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropIndex(['type', 'is_published']);
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn([
                'type',
                'extension_destination',
                'extension_nights',
                'extension_details',
            ]);
        });
    }
};
