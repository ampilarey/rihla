<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The route (companies plan, Phase 3d, from Hijaz's stops): how a departure
 * leaves each hotel for the next place — by coach, by the Haramain train,
 * by car, or to the airport. The order and the nights are already on the
 * hotel, and the dates follow from the flights, so this is the one fact the
 * route needed that nothing recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departure_hotels', function (Blueprint $table): void {
            $table->string('leaves_by', 10)->nullable()->after('nights');
        });
    }

    public function down(): void
    {
        Schema::table('departure_hotels', function (Blueprint $table): void {
            $table->dropColumn('leaves_by');
        });
    }
};
