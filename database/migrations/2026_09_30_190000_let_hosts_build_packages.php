<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packages a host builds — §16 Phase 16.
 *
 * `partner_id` is the host who wrote it; null is Rihla's own, which is
 * every package before this. `submitted_at` is when they handed it to Rihla
 * to price and publish. Publishing stays `is_published`, and staff's.
 *
 * **An index, not a foreign key.** Adding a constrained column makes SQLite
 * rebuild `packages`, and a rebuild cascade-deletes every departure hanging
 * off it — the AGENTS.md trap, which `PackageDepartureTest` hit here: it
 * replays this migration after copying trips and found its departure gone.
 * Hosts are suspended, not deleted; if one ever is, {@see \App\Models\Partner}
 * hands their packages back to Rihla in its `deleting` hook.
 *
 * Down drops the index before the column, in separate calls (AGENTS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->unsignedBigInteger('partner_id')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropIndex(['partner_id']);
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn(['partner_id', 'submitted_at']);
        });
    }
};
