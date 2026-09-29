<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An island holiday a visitor can buy too — §16.14, §16 Phase 15.
 *
 * `packages.sold_to` says which price lists a package sells at: `local`
 * (every package until now, and the default), `tourist` or `both`. Named
 * for what it answers rather than the plan's `audience`, because
 * `Package::audience()` already exists and answers a different question
 * (pilgrims or Maldivian families), and two things called "audience" that
 * disagree would be a trap.
 *
 * `price_tiers.audience` makes a tourist price its own tier, in its own
 * currency: a booking is paid in one currency, so a visitor's price is not
 * a conversion of the local one. Every existing tier is `local`, so every
 * existing departure prices exactly as it did.
 *
 * The unique key gains the audience. The new key is added before the old
 * one goes, so `departure_id` is never without the index its foreign key
 * needs on MySQL (AGENTS.md: errno 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->string('sold_to', 10)->default('local');
        });

        Schema::table('price_tiers', function (Blueprint $table): void {
            $table->string('audience', 10)->default('local');
        });

        Schema::table('price_tiers', function (Blueprint $table): void {
            $table->unique(['departure_id', 'audience', 'occupancy', 'pax_type'], 'price_tiers_departure_audience_unique');
        });

        Schema::table('price_tiers', function (Blueprint $table): void {
            $table->dropUnique(['departure_id', 'occupancy', 'pax_type']);
        });
    }

    public function down(): void
    {
        // The old key cannot hold a tourist tier beside a local one.
        DB::table('price_tiers')->where('audience', '!=', 'local')->delete();

        Schema::table('price_tiers', function (Blueprint $table): void {
            $table->unique(['departure_id', 'occupancy', 'pax_type']);
        });

        Schema::table('price_tiers', function (Blueprint $table): void {
            $table->dropUnique('price_tiers_departure_audience_unique');
        });

        Schema::table('price_tiers', function (Blueprint $table): void {
            $table->dropColumn('audience');
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn('sold_to');
        });
    }
};
