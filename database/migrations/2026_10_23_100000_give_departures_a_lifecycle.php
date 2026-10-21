<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A departure's lifecycle, moved by hand — batch C from Hijaz.
 *
 * `status` used to hold upcoming/current/past, written by whoever edited
 * the form and read by nothing; the dates and the publish switch decided
 * everything. Now it holds draft → open → closed → departed → returned →
 * settled (or cancelled), and the state decides: open is the only state
 * that lists and sells, departed and returned drive the board and the
 * leader's tools, settled closes the books. `is_published` stays as a
 * column the model derives from the state, so nothing that still reads
 * it is lied to.
 *
 * Existing rows are placed by their dates, with literals on both sides as
 * a migration must: one that has come home has nothing to settle, because
 * the books did not exist while it ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departures', function (Blueprint $table): void {
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        Schema::create('departure_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            // Null for a move the migration or a command made.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['departure_id', 'created_at']);
        });

        $today = now()->toDateString();

        DB::table('departures')->where('date_end', '<', $today)
            ->update(['status' => 'settled', 'is_published' => false]);

        DB::table('departures')->where('date_start', '<=', $today)->where('date_end', '>=', $today)
            ->update(['status' => 'departed', 'is_published' => false]);

        DB::table('departures')->where('date_start', '>', $today)->where('is_published', true)
            ->update(['status' => 'open']);

        DB::table('departures')->where('date_start', '>', $today)->where('is_published', false)
            ->update(['status' => 'draft']);
    }

    public function down(): void
    {
        $today = now()->toDateString();

        DB::table('departures')->where('status', 'open')->update(['status' => 'upcoming', 'is_published' => true]);
        DB::table('departures')->whereIn('status', ['draft', 'closed', 'cancelled'])->update(['status' => 'upcoming', 'is_published' => false]);
        DB::table('departures')->where('status', 'departed')->update(['status' => 'current', 'is_published' => false]);
        DB::table('departures')->whereIn('status', ['returned', 'settled'])->update(['status' => 'past', 'is_published' => false]);
        DB::table('departures')->where('date_end', '<', $today)->update(['status' => 'past']);

        Schema::dropIfExists('departure_events');

        Schema::table('departures', function (Blueprint $table): void {
            $table->dropColumn('status_changed_at');
        });
    }
};
