<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts and a ledger (companies plan, Phase 3f, from Hijaz): where the
 * company's money sits — a bank account, the office cash box, a tour
 * leader's float in Makkah — and every movement in or out of one that is
 * not a booking's payment or a departure's cost, both of which the books
 * already read from their own tables.
 *
 * Append-only: an entry is never edited or deleted, only voided with a
 * reason, and stays. `account_number` is ciphertext (EncryptedIdentifier),
 * so the column is wide.
 *
 * Every index on these tables is named explicitly where the generated one
 * could pass MySQL's 64 characters (AGENTS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('name', 80);
            $table->string('kind', 12);
            $table->string('currency', 3);
            $table->string('bank', 80)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->text('account_number')->nullable();
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ledger_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('kind', 8);
            $table->string('slug', 40);
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('direction', 3);
            $table->string('kind', 10);
            $table->foreignId('category_id')->nullable()->constrained('ledger_categories')->nullOnDelete();
            $table->foreignId('departure_id')->nullable()->constrained()->nullOnDelete();
            $table->date('occurred_on');
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('description', 255);
            $table->string('counterparty', 120)->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('group_key', 40)->nullable()->index();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_categories');
        Schema::dropIfExists('accounts');
    }
};
