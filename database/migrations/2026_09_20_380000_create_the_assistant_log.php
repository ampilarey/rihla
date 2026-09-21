<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every question the pilgrim assistant was asked, and what it said — §9.6.
 *
 * §9.6 requires "logged prompts/responses". This is that log, and it is
 * also the only way anybody will find out that the assistant is refusing
 * everything, or refusing the wrong things: a referral is recorded with
 * its reason, so the pattern of what it cannot answer is readable.
 *
 * ## It is not kept for ever
 *
 * A religious question is sensitive, and often personal in a way the asker
 * would not expect to be filed. `assistant:prune` deletes exchanges older
 * than `assistant.log_retention_days`. Nothing runs it automatically —
 * there is no queue worker (ADR 0002) — so it belongs in the cPanel cron
 * beside the other maintenance commands.
 *
 * `traveller_id` is nullable and nullOnDelete: the log survives the
 * traveller leaving, without them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_exchanges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('traveller_id')->nullable()->constrained()->nullOnDelete();

            $table->text('question');
            $table->text('answer');

            // Whether it handed the question to a person, and why. The
            // reason is the useful column: a month of them says what the
            // Knowledge Centre is missing.
            $table->boolean('referred')->default(true);
            $table->string('reason', 500)->nullable();

            // What it was allowed to read, so an answer can be audited
            // against the passages it actually had.
            $table->json('sources')->nullable();

            $table->string('provider', 30)->default('none');
            $table->string('model', 60)->default('none');
            $table->string('locale', 10)->default('en');

            $table->timestamps();

            $table->index(['referred', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_exchanges');
    }
};
