<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest's way into their own stay — §16.7, §16 Phase 13.3.
 *
 * Mirrors `portal_accesses` exactly and is a separate table for the reason
 * the family portal gave: a stay token must never be accepted where a
 * pilgrim token is, and one table with a `kind` column is one forgotten
 * `where` away from that. Only the SHA-256 of the token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            // Null when the guest made the stay themselves.
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('first_used_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['stay_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_accesses');
    }
};
