<?php

use App\Models\Concerns\HasNotices;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A notice stops belonging to a booking and starts belonging to *something*
 * — §15.7.
 *
 * A stay is not a booking: no departure, no seats, no travellers. So a
 * guesthouse customer whose partner said yes, or whose deposit window is
 * closing, had nowhere for that news to be recorded — the one table in this
 * codebase whose entire job is "somebody needs to be told" could not hold
 * it. `noticeable_type`/`noticeable_id` is that seam, and it is the same
 * seam `make_payments_polymorphic` cut in §15.3 for the same reason.
 *
 * ## The literals are deliberate
 *
 * The backfill writes `'App\Models\Booking'` as a string rather than
 * `Booking::class`. A migration records what happened on the day it ran; a
 * class reference moves when the class does, and this rewrite would then
 * describe a state no database was ever in. `AGENTS.md` records the defect
 * that taught it.
 *
 * ## The cascade has to be rebuilt in PHP
 *
 * `booking_id` was `constrained()->cascadeOnDelete()`, so deleting a
 * booking took its notices with it — and a notice about a booking that no
 * longer exists is not about anything. A polymorphic column carries no
 * foreign key, so the database can no longer do that. {@see HasNotices}
 * restores it on the models, and `NoticeTest` fails if either owner stops
 * cleaning up after itself. This is the part of a polymorphic conversion
 * that is silent: nothing errors, the rows simply start surviving.
 *
 * ## Why the drop is three separate calls
 *
 * `AGENTS.md` names this exact trap, and both halves of the order come from
 * a different engine:
 *
 *  - **MySQL** refuses to drop an index a foreign key still needs (errno
 *    1553), so the constraint goes first.
 *  - **SQLite** rebuilds the whole table on `dropColumn` and fails on
 *    anything still naming the departing column, so the index goes before
 *    the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table): void {
            $table->string('noticeable_type')->nullable()->after('id');
            $table->unsignedBigInteger('noticeable_id')->nullable()->after('noticeable_type');
        });

        // Literals on both sides. See the docblock.
        DB::table('notices')->update([
            'noticeable_type' => 'App\Models\Booking',
            'noticeable_id' => DB::raw('booking_id'),
        ]);

        Schema::table('notices', function (Blueprint $table): void {
            $table->string('noticeable_type')->nullable(false)->change();
            $table->unsignedBigInteger('noticeable_id')->nullable(false)->change();
        });

        // The same shape the old index had: one of each kind per owner at a
        // time is what stops a nightly sweep stacking fourteen identical
        // reminders, and that lookup is `Notice::raise()`'s whole cost.
        Schema::table('notices', function (Blueprint $table): void {
            $table->index(['noticeable_type', 'noticeable_id', 'kind', 'handled_at'], 'notices_owner_kind_handled_index');
        });

        // One: the constraint. MySQL will not let the index go while this
        // stands.
        Schema::table('notices', function (Blueprint $table): void {
            $table->dropForeign(['booking_id']);
        });

        // Two: the index. SQLite will not rebuild the table while this
        // still names the column.
        Schema::table('notices', function (Blueprint $table): void {
            $table->dropIndex(['booking_id', 'kind', 'handled_at']);
        });

        // Three: the column itself.
        Schema::table('notices', function (Blueprint $table): void {
            $table->dropColumn('booking_id');
        });
    }

    public function down(): void
    {
        // A notice about a stay has no `booking_id` to go back to, and
        // inventing one would file a guesthouse's news against somebody
        // else's pilgrimage. Refusing is the honest outcome; there is
        // nothing to reverse this into.
        $foreign = DB::table('notices')
            ->where('noticeable_type', '!=', 'App\Models\Booking')
            ->count();

        if ($foreign > 0) {
            throw new RuntimeException(
                "Cannot roll this back: {$foreign} notice(s) belong to something other than a booking, "
                .'and `notices.booking_id` cannot hold them. Move or delete those rows first.',
            );
        }

        Schema::table('notices', function (Blueprint $table): void {
            $table->unsignedBigInteger('booking_id')->nullable()->after('id');
        });

        DB::table('notices')->update(['booking_id' => DB::raw('noticeable_id')]);

        Schema::table('notices', function (Blueprint $table): void {
            $table->unsignedBigInteger('booking_id')->nullable(false)->change();
        });

        Schema::table('notices', function (Blueprint $table): void {
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            $table->index(['booking_id', 'kind', 'handled_at']);
        });

        Schema::table('notices', function (Blueprint $table): void {
            $table->dropIndex('notices_owner_kind_handled_index');
        });

        Schema::table('notices', function (Blueprint $table): void {
            $table->dropColumn(['noticeable_type', 'noticeable_id']);
        });
    }
};
