<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what, and when.
 *
 * Roles say who *may* act; this says who *did*. The Ministry of Islamic
 * Affairs licenses Umrah operators and expects auditable records of what each
 * pilgrim was promised, so this is a compliance requirement as much as an
 * engineering one — and it has to exist before bookings, payments and
 * passport documents arrive, not after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable and nulled on delete: an audit record must outlive the
            // account that produced it, or removing a member of staff would
            // erase the evidence of what they did.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot, because the name above can disappear or change. A log
            // that reads "deleted by (unknown)" is barely a log at all.
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();

            $table->string('event', 20);
            $table->morphs('auditable');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('url')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The two questions actually asked of this table: what happened to
            // this record, and what has this person been doing.
            $table->index(['auditable_type', 'auditable_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
