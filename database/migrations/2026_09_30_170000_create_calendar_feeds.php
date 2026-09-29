<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Other sites' calendars, imported — §16 Phase 16.
 *
 * One feed per room type: the iCal link Airbnb, Booking.com or the host's
 * own system publishes for that room. Each sync writes the nights it names
 * as `blocked_dates` with source `ical` (a value that has existed since
 * Phase 9.2, waiting for this). Import only — nothing is exported.
 *
 * The link is a secret (anybody holding it can read that calendar), so it
 * is ciphertext at rest (`EncryptedIdentifier`), hence `text`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_type_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->text('url');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->unsignedInteger('nights_blocked')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
