<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transport, phase T1 (docs/TRANSPORT_PLAN.md): boats, routes, sailings,
 * bookings and the passengers on them.
 *
 * Links into other modules' tables — the operator (`partners`), the islands
 * and the customer — are plain indexed columns with no foreign key, for the
 * SQLite rebuild and MySQL drop-order traps AGENTS.md records; the models
 * do the referential work. Foreign keys only run between these five
 * tables, which nothing else hangs off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id')->index();
            $table->string('name', 80);
            $table->string('kind', 20);
            $table->unsignedSmallInteger('seats');
            $table->unsignedInteger('cargo_kg')->nullable();
            $table->string('registration_number', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('boat_routes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id')->index();
            $table->unsignedBigInteger('origin_island_id')->index();
            $table->unsignedBigInteger('destination_island_id')->index();
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('currency', 3)->default('MVR');
            $table->unsignedInteger('adult_fare_minor');
            $table->unsignedInteger('child_fare_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sailings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boat_route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->dateTime('departs_at');
            $table->unsignedSmallInteger('seats_total');
            // Held and confirmed seats together, kept under the row lock in
            // TransportDesk — never summed from the bookings at read time,
            // which two bookers racing would both see as room.
            $table->unsignedSmallInteger('seats_taken')->default(0);
            $table->string('status', 20)->default('scheduled');
            $table->string('cancelled_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['boat_route_id', 'departs_at']);
            $table->index('departs_at');
        });

        Schema::create('boat_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sailing_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('reference', 30)->unique();
            $table->string('contact_name', 120);
            $table->string('contact_phone', 30);
            $table->unsignedSmallInteger('seats');
            $table->string('currency', 3);
            $table->unsignedInteger('total_minor');
            $table->unsignedInteger('paid_minor')->default(0);
            $table->string('status', 20)->default('held');
            $table->dateTime('held_until')->nullable();
            $table->unsignedBigInteger('booked_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'held_until']);
        });

        Schema::create('boat_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boat_booking_id')->constrained()->cascadeOnDelete();
            $table->string('full_name', 120);
            $table->boolean('is_child')->default(false);
            $table->string('token', 40)->unique();
            $table->dateTime('boarded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boat_tickets');
        Schema::dropIfExists('boat_bookings');
        Schema::dropIfExists('sailings');
        Schema::dropIfExists('boat_routes');
        Schema::dropIfExists('vessels');
    }
};
