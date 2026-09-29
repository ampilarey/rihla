<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions and long-stay discounts — §16 Phase 16.
 *
 * A percentage off the room, on a listing or one of its rooms: *long stay*
 * from a number of nights, or a *promotion* for check-ins inside a window
 * (optionally also from a number of nights). One discount applies to a
 * quote, the largest the stay qualifies for — never two stacked. The
 * quote freezes it into the stay's `rate_snapshot` with the price, so
 * ending a promotion tomorrow changes nobody's booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 20);
            $table->unsignedTinyInteger('percent');
            $table->unsignedSmallInteger('min_nights')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('audience', 10)->default('both');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['property_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_discounts');
    }
};
