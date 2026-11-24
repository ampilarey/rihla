<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boarding — docs/TRANSPORT_PLAN.md (T3). `boarded_at` came with T1; this
 * records which crew member scanned the passenger on. A plain indexed
 * column, as every user pointer added to an existing table is here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('boarded_by')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('boat_tickets', function (Blueprint $table): void {
            $table->dropIndex(['boarded_by']);
        });

        Schema::table('boat_tickets', function (Blueprint $table): void {
            $table->dropColumn('boarded_by');
        });
    }
};
