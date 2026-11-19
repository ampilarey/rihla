<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff sign in with a mobile number as well as an e-mail address
 * (companies plan, Phase 3i, from Hijaz). Stored as the number's key —
 * seven digits for a Maldivian mobile — so "+960 777-1234" and "7771234"
 * are the same sign-in. Unique: one number opens one account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
