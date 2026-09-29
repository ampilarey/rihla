<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paying hosts — §16.9, §16 Phase 16.
 *
 * Where to send a host's money, and a record of each bank transfer Finance
 * makes against a statement. A payout is recorded, never initiated: Rihla
 * pays by bank transfer from its own bank, and this is the ledger of what
 * was sent, so the host's statement can say what is still owed.
 *
 * The account number is ciphertext (`EncryptedIdentifier`), hence `text`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->string('payout_bank_name', 120)->nullable();
            $table->string('payout_account_name')->nullable();
            $table->text('payout_account_number')->nullable();
        });

        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained();
            $table->foreignId('host_statement_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->date('paid_on');
            $table->string('reference', 120);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['partner_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn(['payout_bank_name', 'payout_account_name', 'payout_account_number']);
        });
    }
};
