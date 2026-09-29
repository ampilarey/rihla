<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tourist and local prices on one calendar — §16.5, §16.3 decision 6.
 *
 * Additive only. Every existing room keeps `base_rate_minor` as its tourist
 * price, every existing season and stay is a tourist one, and a room with
 * no local price is not sold to locals — so nothing already on sale moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table): void {
            // In the local currency (config marketplace.currencies.local).
            // Null means "not sold to locals", never "free".
            $table->unsignedBigInteger('local_rate_minor')->nullable()->after('base_rate_minor');
        });

        Schema::table('rates', function (Blueprint $table): void {
            $table->string('audience', 10)->default('tourist')->after('room_type_id');
            $table->index(['room_type_id', 'audience', 'starts_on', 'ends_on'], 'rates_room_audience_season_index');
        });

        Schema::table('stays', function (Blueprint $table): void {
            $table->string('audience', 10)->default('tourist')->after('room_type_id');
        });
    }

    public function down(): void
    {
        // Index first, then the column, each in its own call — AGENTS.md.
        Schema::table('stays', function (Blueprint $table): void {
            $table->dropColumn('audience');
        });

        Schema::table('rates', function (Blueprint $table): void {
            $table->dropIndex('rates_room_audience_season_index');
        });

        Schema::table('rates', function (Blueprint $table): void {
            $table->dropColumn('audience');
        });

        Schema::table('room_types', function (Blueprint $table): void {
            $table->dropColumn('local_rate_minor');
        });
    }
};
