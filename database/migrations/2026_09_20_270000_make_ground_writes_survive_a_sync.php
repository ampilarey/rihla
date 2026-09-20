<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a write made on a phone with no signal needs — §6.3.
 *
 * §6.3 says the Tour Leader Portal "must work offline — connectivity in
 * transit is unreliable; design for queued writes and sync". A queue that
 * replays is only safe if the server can tell a replay from a new write,
 * and only honest if the record says when the thing *happened* rather than
 * when the phone got a signal back.
 *
 * ## `client_uuid` on incidents
 *
 * An incident raised offline and replayed twice would otherwise be two
 * incidents. The phone generates the id before the first attempt, so every
 * attempt carries the same one and the second is recognised. Nullable,
 * because an incident typed in the office has no phone to generate one.
 *
 * Roll-call marks need no such column: the mark is already keyed on
 * (roll_call_id, traveller_id) and replaying one sets the same value again.
 *
 * ## `marked_at` and `happened_at` from the phone
 *
 * A count taken at the coach door at nine and synced at two in the
 * afternoon is a nine o'clock count. `incidents.happened_at` already
 * carries this idea; `roll_call_marks` gains it here.
 *
 * It also decides the order: **the leader's clock wins, not the order the
 * server happened to receive things in.** A mark that arrives carrying an
 * earlier time than the one already stored is discarded, so a retry of an
 * old attempt cannot undo a newer correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('reference');
        });

        Schema::table('roll_call_marks', function (Blueprint $table) {
            // Nullable rather than defaulted: a mark made before this
            // existed has no honest value, and `created_at` is not it — it
            // is when the row was written, which is the very thing this
            // column exists to stop standing in for.
            $table->timestamp('marked_at')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('roll_call_marks', function (Blueprint $table) {
            $table->dropColumn('marked_at');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
