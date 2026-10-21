<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a change arrived from: the staff panel, the host panel, a pilgrim's
 * own portal, the tour leader's phone page, the console — and, next, a chat
 * with the Telegram bot. A trail that says "updated by Aishath" cannot tell
 * a verification done at a desk from one tapped in a chat, and the second
 * is the one an auditor asks about. Every row before this was written from
 * the panel or the console, so the default names the common case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('channel', 20)->default('staff')->after('event');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });
    }
};
