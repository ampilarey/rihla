<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staff Telegram bot — batch A4 from Hijaz.
 *
 * A link binds a staff account to one chat; a code is the one-time way a
 * link is made; an update row is the record of every delivery Telegram
 * made, so a retried delivery is answered once. Nothing here is about a
 * customer: the chats are staff's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chat_id');
            $table->string('telegram_username', 64)->nullable();
            $table->string('telegram_name', 128)->nullable();
            // What the chat owes an answer to: a refusal's reason, asked
            // for and awaited in the next message.
            $table->json('pending')->nullable();
            $table->timestamp('linked_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'revoked_at']);
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('telegram_link_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->string('kind', 30)->nullable();
            $table->string('command', 40)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('handled_at')->nullable();
            $table->string('error', 255)->nullable();

            $table->index(['chat_id', 'kind', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_link_codes');
        Schema::dropIfExists('telegram_links');
    }
};
