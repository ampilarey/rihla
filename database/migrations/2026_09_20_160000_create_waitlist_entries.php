<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wants a seat on a departure that has none left.
 *
 * The plan's §5.1: per departure, with auto-promotion when a seat is
 * released. A sold-out departure is currently a dead end — the page says
 * "Fully booked" and the visitor leaves — while in practice seats *do* come
 * back: a hold lapses, a booking is cancelled, a passport turns out to be
 * expired. Somebody should be told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('seats')->default(1);

            // What they would want if a seat appears. Optional: somebody
            // joining a waiting list is saying "yes please", not filling in
            // a booking form, and demanding a room type here would lose
            // people for no gain.
            $table->string('occupancy', 20)->nullable();

            $table->string('status', 20)->default('waiting');

            // The offer. When seats are released, the earliest entry that
            // fits gets them *actually held* — not merely flagged — because
            // an offer that does not reserve anything is a race the customer
            // loses while staff are still typing a message.
            $table->foreignId('seat_hold_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('offer_expires_at')->nullable();

            // What it became, once they book.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // The promotion query: who is waiting on this departure, oldest
            // first.
            $table->index(['departure_id', 'status', 'created_at']);
            $table->index(['status', 'offer_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
