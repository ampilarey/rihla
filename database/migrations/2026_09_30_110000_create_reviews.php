<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guests' reviews — §16.5, §16.11, §16 Phase 14.5.
 *
 * One per stay, enforced by the unique key rather than by a check a second
 * request could race past. The property and host are copied onto the row
 * so an average is one indexed query, not a join through every stay.
 *
 * An invitation is a separate table for the portal's reason: its token is
 * a credential, stored only as a hash, and it can be spent once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stay_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->unsignedTinyInteger('cleanliness')->nullable();
            $table->unsignedTinyInteger('accuracy')->nullable();
            $table->unsignedTinyInteger('communication')->nullable();
            $table->unsignedTinyInteger('value')->nullable();
            $table->text('body')->nullable();
            $table->string('locale', 5)->default('en');
            $table->timestamp('submitted_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('host_reply')->nullable();
            $table->timestamp('host_replied_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'published_at']);
            $table->index(['partner_id', 'published_at']);
        });

        Schema::create('review_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_invitations');
        Schema::dropIfExists('reviews');
    }
};
