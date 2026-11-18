<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional trips (companies plan, Phase 3e, from Hijaz): Taif, Jeddah,
 * a Ziyarah included in the price. Priced in riyal and paid in Saudi
 * Arabia, so each family's places on one are their own payable — with
 * their own reference, payments and invoice — rather than lines on a
 * booking that is in rufiyaa (the plan's "different by design": no split
 * currency balances).
 *
 * New tables, so their foreign keys cost nothing: nothing hangs off them
 * yet for a SQLite rebuild to cascade through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departure_excursions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('city', 12)->nullable();
            $table->date('scheduled_on')->nullable();
            $table->boolean('is_included')->default(false);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('provider', 120)->nullable();
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('excursion_bookings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('reference', 24)->nullable()->unique();
            // Restricted, like a booking's departure: places somebody paid
            // for are a financial record, not a row to vanish with the trip.
            $table->foreignId('departure_excursion_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3)->default('SAR');
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->string('status', 12)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['departure_excursion_id', 'booking_id']);
        });

        Schema::create('excursion_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreignId('excursion_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_traveller_id')->constrained('booking_travellers')->cascadeOnDelete();
            $table->bigInteger('amount_minor')->default(0);
            $table->timestamps();

            $table->unique(['excursion_booking_id', 'booking_traveller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excursion_participants');
        Schema::dropIfExists('excursion_bookings');
        Schema::dropIfExists('departure_excursions');
    }
};
