<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A host's add-ons — §16.14, §16 Phase 15.
 *
 * Extras a guest can pick when they book: an airport transfer, breakfast,
 * a snorkelling trip. Priced per stay or per person, with a tourist price
 * in the listing's currency and a local one in rufiyaa — either optional,
 * and an add-on with no price for an audience is simply not offered to it,
 * the rule a room follows. What a guest picks is written onto the stay's
 * bill as an `extra` line at the price of the day, so changing an add-on
 * afterwards changes nobody's booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('pricing', 20)->default('per_stay');
            $table->unsignedInteger('price_minor')->nullable();
            $table->unsignedInteger('local_price_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_addons');
    }
};
