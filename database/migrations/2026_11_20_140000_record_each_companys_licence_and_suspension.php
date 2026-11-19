<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company's licence, as Rihla's admin checked it before adding the
 * company (decision 17), and why it was suspended — companies plan,
 * Phase 5b.
 *
 * The licence is not the company's registration number (which its
 * documents print): it is the Hajj and Umrah operator's licence, and the
 * register it was checked against is recorded because which register that
 * is remains the owner's open question 3. `suspended_reason` already exists
 * (the host side's, §16); this adds when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->string('licence_number', 60)->nullable();
            $table->date('licence_expires_on')->nullable();
            $table->string('licence_register', 120)->nullable();
            $table->timestamp('suspended_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['licence_number', 'licence_expires_on', 'licence_register', 'suspended_at'] as $column) {
            Schema::table('partners', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }
};
