<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flight groups (companies plan, Phase 3c, from Hijaz): the people of a
 * departure who fly out on one flight and home on another. A departure of
 * two hundred rarely fits one aircraft, so it flies in groups on different
 * days, and each group has its own buses on the ground.
 *
 * `booking_travellers.flight_group_id` and `bus_groups.flight_group_id` are
 * plain indexed columns, not foreign keys: adding a constrained column makes
 * SQLite rebuild the table, and the rebuild cascade-deletes every row
 * hanging off it (AGENTS.md). `FlightGroup`'s `deleting` hook does the
 * referential work instead, the way `BusGroup` does for `bus_group_id`.
 *
 * `bus_groups.colour` is new with it: a group's buses are told apart at the
 * airport by the colour of the sticker, the lanyard and the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->foreignId('outbound_flight_id')->nullable()->constrained('departure_flights')->nullOnDelete();
            $table->foreignId('return_flight_id')->nullable()->constrained('departure_flights')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['departure_id', 'name']);
        });

        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->unsignedBigInteger('flight_group_id')->nullable()->after('bus_group_id')->index();
        });

        Schema::table('bus_groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('flight_group_id')->nullable()->after('departure_id')->index();
            $table->string('colour', 7)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        // The index, then the column, each in its own call (AGENTS.md).
        Schema::table('bus_groups', function (Blueprint $table): void {
            $table->dropIndex(['flight_group_id']);
        });

        Schema::table('bus_groups', function (Blueprint $table): void {
            $table->dropColumn(['flight_group_id', 'colour']);
        });

        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->dropIndex(['flight_group_id']);
        });

        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->dropColumn('flight_group_id');
        });

        Schema::dropIfExists('flight_groups');
    }
};
