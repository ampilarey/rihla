<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who works for a host — §16.5, §16.6, §16 Phase 14.1.
 *
 * A host is a `partners` row; the people who run it are ordinary `users`
 * joined to it with a role (owner, manager, reception). One user may work
 * for two guesthouses and switch between them in the `/host` panel, and a
 * member of Rihla's staff who also owns one simply has both panels.
 *
 * An invitation is a separate table rather than a pending membership: it
 * names an e-mail address that may not be a user yet, and its token is a
 * credential, stored only as a hash (the portal's rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'user_id']);
        });

        Schema::create('host_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_invitations');
        Schema::dropIfExists('host_memberships');
    }
};
