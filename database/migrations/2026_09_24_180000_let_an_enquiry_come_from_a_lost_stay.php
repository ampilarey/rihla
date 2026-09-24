<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a follow-up came from — §15.7.
 *
 * A stay that a partner declined, or whose deposit window closed, used to
 * simply stop existing as far as anybody at Rihla was concerned. The plan
 * is direct about why that is wrong: *"so a lost stay is a follow-up
 * rather than a silence."* Somebody asked for a guesthouse and did not get
 * one, and that is a person to ring, not a row to leave alone.
 *
 * `stay_id` is the mirror of `booking_id`, which records what an enquiry
 * *became*. This records what it *was* — the ask that fell through and
 * produced the follow-up.
 *
 * `nullOnDelete` for the same reason the package and departure columns
 * carry it: an enquiry outlives the thing it was about, and losing the
 * enquiry alongside it would lose the person.
 *
 * `property_id` as well, because the useful first sentence of the call is
 * "you asked about Maafushi View" — and a stay that was later removed
 * would otherwise take the only record of which guesthouse with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->foreignId('stay_id')->nullable()->after('departure_id')
                ->constrained()->nullOnDelete();

            $table->foreignId('property_id')->nullable()->after('stay_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Each constrained column dropped in its own statement, foreign key
        // first. MySQL refuses to drop an index a foreign key still needs
        // (errno 1553) and SQLite rebuilds the table and fails on anything
        // still naming a departing column — the ordering trap AGENTS.md
        // records at length.
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->dropForeign(['stay_id']);
            $table->dropForeign(['property_id']);
        });

        Schema::table('enquiries', function (Blueprint $table): void {
            $table->dropColumn(['stay_id', 'property_id']);
        });
    }
};
