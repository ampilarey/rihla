<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages about a stay — §16.5, §16.11, §16 Phase 14.5.
 *
 * One conversation per stay, between the guest, the host and Rihla. The
 * stay *is* the thread: the plan drew a `stay_threads` table holding only
 * `last_message_at`, which is a MAX() over this one, and a second place to
 * keep in step. Each message carries its stay directly, so the right-to-
 * erasure route reaches it through the stay like everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->string('sender', 10);
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('sent_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['stay_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_messages');
    }
};
