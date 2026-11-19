<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer portal with a password (companies plan, Phase 3h, from
 * Hijaz): a customer signs in with their mobile number and a password they
 * chose, and sees every booking they have. A booking link still opens one
 * booking without any of this.
 *
 * Hashed with Laravel's own hasher; nothing reads it back. Plain columns, so
 * adding them costs `customers` no SQLite rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('portal_password')->nullable();
            $table->timestamp('portal_password_set_at')->nullable();
            $table->timestamp('portal_last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['portal_password', 'portal_password_set_at', 'portal_last_login_at']);
        });
    }
};
