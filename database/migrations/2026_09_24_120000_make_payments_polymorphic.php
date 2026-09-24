<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payment stops belonging to a booking and starts belonging to *something*
 * — §15.3 (Phase 8.6).
 *
 * Phase 9 sells a stay, which is not a booking: it has no departure, no
 * seats and no travellers, so it cannot be squeezed into `bookings` and its
 * money still has to land in the same ledger. `payable_type`/`payable_id`
 * is that seam, and today every row through it is a Booking.
 *
 * ## The literals are deliberate
 *
 * The backfill writes `'App\Models\Booking'` as a string rather than
 * `Booking::class`. A migration records what happened on the day it ran; a
 * class reference moves when the class does, and the rewrite would then
 * describe a state no database was ever in. That is defect D95 in the
 * upgrade plan — `2026_09_18_170000_rebrand_stored_banner_colours` pointed
 * at `Brand::WINE` and stamped whatever the constant held that morning.
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
 *
 * `dropConstrainedForeignId()` bundles the constraint and the column into
 * one blueprint and leaves indexes alone, so it cannot express this at all.
 * Verified against a real MySQL before it was pushed, because SQLite passes
 * the wrong order happily and takes the whole MySQL suite down with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payable_type')->nullable()->after('id');
            $table->unsignedBigInteger('payable_id')->nullable()->after('payable_type');
        });

        // Literals on both sides. See the docblock.
        DB::table('payments')->update([
            'payable_type' => 'App\Models\Booking',
            'payable_id' => DB::raw('booking_id'),
        ]);

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payable_type')->nullable(false)->change();
            $table->unsignedBigInteger('payable_id')->nullable(false)->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['payable_type', 'payable_id', 'status']);
        });

        // One: the constraint. MySQL will not let the index go while this
        // stands.
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['booking_id']);
        });

        // Two: the index. SQLite will not rebuild the table while this
        // still names the column.
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['booking_id', 'status']);
        });

        // Three: the column itself.
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('booking_id');
        });
    }

    public function down(): void
    {
        // A payment against anything but a booking has no `booking_id` to go
        // back to, and inventing one would point real money at the wrong
        // record. Refusing is the honest outcome; there is nothing to
        // reverse this into.
        $foreign = DB::table('payments')
            ->where('payable_type', '!=', 'App\Models\Booking')
            ->count();

        if ($foreign > 0) {
            throw new RuntimeException(
                "Cannot roll this back: {$foreign} payment(s) belong to something other than a booking, "
                .'and `payments.booking_id` cannot hold them. Move or delete those rows first.',
            );
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('booking_id')->nullable()->after('id');
        });

        DB::table('payments')->update(['booking_id' => DB::raw('payable_id')]);

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('booking_id')->nullable(false)->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
            $table->index(['booking_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['payable_type', 'payable_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['payable_type', 'payable_id']);
        });
    }
};
