<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each Umrah company's own Telegram bot — Phase 2d of
 * docs/UMRAH_COMPANIES_PLAN.md (decision 7).
 *
 * - `partners.telegram_bot_token`: the token BotFather gives the company,
 *   encrypted at rest by the model's cast. A token is a credential: whoever
 *   holds it can read and send every message the bot does. Text, because
 *   ciphertext is far longer than the token.
 * - `telegram_updates`: an update id is unique per bot, not across bots, so
 *   two companies' bots can deliver the same number. The duplicate check
 *   becomes per company: `(update_id, company_id)`, added before the old
 *   index goes. Plain index operations, no table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->text('telegram_bot_token')->nullable();
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->unique(['update_id', 'company_id']);
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropUnique(['update_id']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->unique(['update_id']);
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropUnique(['update_id', 'company_id']);
        });

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn('telegram_bot_token');
        });
    }
};
