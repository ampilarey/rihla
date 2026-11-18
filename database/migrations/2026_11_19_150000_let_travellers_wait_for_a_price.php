<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration before a price — companies plan, Phase 3b (Hijaz parity).
 *
 * A departure can take names at the office before it has any price. Such a
 * traveller's seat says so: `awaits_price`, true from the desk until the
 * departure's price for their room arrives, when they are priced and it
 * goes false. A flag rather than "no tier and nothing to pay", because a
 * free place (an infant, a tour leader) also has no amount, and it is the
 * one thing the payment guard reads. A plain boolean, added in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->boolean('awaits_price')->default(false);
            // Which price list they wait for (local or visitor): the booking
            // does not record it, the tier that prices it later must match.
            $table->string('awaits_audience', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->dropColumn(['awaits_price', 'awaits_audience']);
        });
    }
};
