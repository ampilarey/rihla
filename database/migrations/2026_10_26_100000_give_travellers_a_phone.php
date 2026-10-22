<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Families — batch C from Hijaz. The head of a family holds its documents
 * and is the one the office and the tour leader ring, and until now the
 * only number on file was the booker's: a son who books for his parents
 * left a list that rang him in Malé about a father lost in Makkah.
 *
 * A plain nullable column with no index and no constraint, so SQLite adds
 * it in place rather than rebuilding `travellers` (the rebuild that
 * cascade-deletes rows hanging off a table, recorded in AGENTS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travellers', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('travellers', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
