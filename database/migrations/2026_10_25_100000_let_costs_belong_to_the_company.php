<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company books — batch C from Hijaz. A cost may now belong to the
 * company rather than to one departure (the office rent, the agent's
 * annual fee, the software), so `departure_id` becomes nullable. Nothing
 * hangs off `departure_costs`, so SQLite's rebuild of the table on a
 * column change cascade-deletes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departure_costs', function (Blueprint $table): void {
            $table->foreignId('departure_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('departure_costs', function (Blueprint $table): void {
            $table->foreignId('departure_id')->nullable(false)->change();
        });
    }
};
