<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The booker's key to their tickets — docs/TRANSPORT_PLAN.md (T2).
 *
 * A reference such as RIH-T-2026-00042 is counted, so anybody could guess
 * the next one; `BookingFlowTest` refuses any public booking URL that
 * carries one. The tickets page is found by this random key instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table): void {
            $table->string('tickets_key', 64)->nullable()->unique();
        });

        DB::table('boat_bookings')->whereNull('tickets_key')->orderBy('id')->each(function (object $row): void {
            DB::table('boat_bookings')->where('id', $row->id)->update(['tickets_key' => Str::random(40)]);
        });
    }

    public function down(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table): void {
            $table->dropUnique(['tickets_key']);
        });

        Schema::table('boat_bookings', function (Blueprint $table): void {
            $table->dropColumn('tickets_key');
        });
    }
};
