<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Island holidays on the package engine — §15.5 (Phase 10).
 *
 * Phase 8.7 gave a package a `type` and listed `island_holiday` among
 * them, saying it *"sells nothing yet — Phase 10 builds it on this same
 * engine"*. This is that phase, and it adds only what the engine does not
 * already have.
 *
 * ## What is deliberately *not* added
 *
 * The plan lists an `audience` column (`locals`). It is not here, because
 * `type` already answers it: an Umrah is sold to pilgrims and an island
 * holiday is sold to Maldivian families, and nothing at Rihla is both. A
 * second column stating a fact the first one already states is a second
 * copy to go stale — the shape `AGENTS.md` records for a palette with more
 * than one source of truth. `Package::audience()` derives it, so the
 * reading code is unchanged and there is nothing to keep in step.
 *
 * Currency is not here either: `price_tiers.currency` already defaults to
 * MVR, which is exactly what §15.2 decision 4 wants for a local family.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            // An island holiday may be built *on* a guesthouse, so its
            // nights can come off that guesthouse's calendar rather than
            // being promised twice. Nullable and restricted: most packages
            // have no property, and a property with packages against it is
            // not something a tidy-up may delete.
            $table->foreignId('property_id')->nullable()->after('type')
                ->constrained()->restrictOnDelete();

            // A Fulidhoo weekend is a group on a boat on a Thursday — a
            // fixed departure, like an Umrah. But a family may equally want
            // four nights of their own choosing, and that is the same
            // product sold differently rather than a second product.
            $table->boolean('flexible_dates')->default(false)->after('nights');
            $table->unsignedSmallInteger('min_nights')->nullable()->after('flexible_dates');
        });
    }

    public function down(): void
    {
        // The constrained column first, and in its own statement. MySQL
        // refuses to drop an index a foreign key still needs (errno 1553),
        // and SQLite rebuilds the table and fails on anything still naming
        // a departing column — the ordering trap `AGENTS.md` records at
        // length.
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropForeign(['property_id']);
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn(['property_id', 'flexible_dates', 'min_nights']);
        });
    }
};
