<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second factor on staff accounts — §10.4's "MFA for staff".
 *
 * `mfa_secret` and `mfa_recovery_codes` are cast `encrypted` on the model,
 * so what sits in the column is ciphertext under `APP_KEY`. A shared-host
 * database dump is the realistic threat here (ADR 0002), and a TOTP secret
 * in a dump is a permanent second factor for whoever reads it.
 *
 * `mfa_confirmed_at` is the switch. A secret alone means somebody started
 * enrolling and wandered off; the factor is only live once a code minted
 * from it has been proved, or enrolment locks people out of their own
 * account by half-finishing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('password');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes']);
        });
    }
};
