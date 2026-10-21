<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The airline's two facts about a traveller: the ticket number and the
 * seat. Both print on the manifest the airline is handed, and neither had
 * anywhere to live. No foreign key, but `booking_travellers` is re-created
 * by PackageDepartureTest's replay, so this is on its DEPENDENT_MIGRATIONS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->string('ticket_number', 30)->nullable()->after('room_reference');
            $table->string('seat_number', 8)->nullable()->after('ticket_number');
        });
    }

    public function down(): void
    {
        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->dropColumn(['ticket_number', 'seat_number']);
        });
    }
};
