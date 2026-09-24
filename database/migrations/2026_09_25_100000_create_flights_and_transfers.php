<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a departure actually moves — §8.3's flight and transport records.
 *
 * Until now the departure board said, on its face, that flights and
 * transport were not recorded anywhere: absent rather than green. These are
 * the records that let it say something true instead.
 *
 * Times are the **local wall-clock time at the place it happens** — a
 * Malé departure in Malé time, a Jeddah arrival in Jeddah time — which is
 * what is printed on a ticket and what a pilgrim reads off a board. They
 * are stored as given and never converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departure_flights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 20);
            $table->string('airline', 100);
            $table->string('flight_number', 20);
            $table->string('from_airport', 3);
            $table->string('to_airport', 3);
            $table->dateTime('departs_at');
            $table->dateTime('arrives_at')->nullable();
            $table->unsignedSmallInteger('seats')->nullable();
            $table->string('booking_reference', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['departure_id', 'departs_at']);
        });

        Schema::create('departure_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->string('mode', 20);
            $table->string('from_place', 150);
            $table->string('to_place', 150);
            $table->string('meeting_point', 255)->nullable();
            $table->string('provider', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['departure_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departure_transfers');
        Schema::dropIfExists('departure_flights');
    }
};
