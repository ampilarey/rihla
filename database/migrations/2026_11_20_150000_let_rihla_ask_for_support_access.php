<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support access, asked for and granted — companies plan, Phase 5c.
 *
 * Somebody at Rihla asks, with a reason; the company's Owner grants it for
 * a few hours or declines; while it lasts that one person can look, never
 * change, and every page they open is recorded where the company sees it.
 * `company_id` is a plain indexed column, as on every company table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('support_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_grant_id')->constrained('support_grants')->cascadeOnDelete();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('path', 500);
            $table->timestamp('visited_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_visits');
        Schema::dropIfExists('support_grants');
    }
};
