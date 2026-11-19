<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each company has paid for — companies plan, Phase 5a (decisions 13
 * and 14). All set by Rihla's admin.
 *
 * - `features`: `{feature: on|read_only}`; a feature not named is off.
 * - The registration fee: its amount and currency, and when it was paid.
 *   The basic website can be switched on only once it is.
 * - `staff_seats`: how many people the company may have; null is no limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->json('features')->nullable();
            $table->unsignedBigInteger('registration_fee_minor')->nullable();
            $table->string('registration_fee_currency', 3)->nullable();
            $table->timestamp('registration_paid_at')->nullable();
            $table->unsignedSmallInteger('staff_seats')->nullable();
        });

    }

    public function down(): void
    {
        foreach (['features', 'registration_fee_minor', 'registration_fee_currency', 'registration_paid_at', 'staff_seats'] as $column) {
            Schema::table('partners', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }
};
