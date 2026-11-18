<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a company's own documents and payment instructions say about it —
 * Phase 2c of docs/UMRAH_COMPANIES_PLAN.md.
 *
 * Until now every invoice, receipt and printed list carried Rihla's name,
 * registration number and telephone, and every bank-transfer instruction
 * Rihla's account, because there was only one business. A company printing
 * from its own panel must print itself (decision 2). Its name, telephone,
 * e-mail and registration number are already partner columns; these are
 * the rest. All nullable and unconstrained, so SQLite adds them in place.
 *
 * - `address`, as printed under the name.
 * - `bank_name`, `bank_account_name` and `bank_accounts` (currency => account
 *   number), the shape `config('payments.bank')` already has for Rihla.
 * - `reference_prefix`: the company's letters in front of its references
 *   (`CRL-B-2026-0012`). Unique, so two companies can never print the same.
 * - `invoice_terms`: the line at the foot of its documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->string('address')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->json('bank_accounts')->nullable();
            $table->string('reference_prefix', 8)->nullable()->unique();
            $table->text('invoice_terms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->dropUnique(['reference_prefix']);
        });

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn(['address', 'bank_name', 'bank_account_name', 'bank_accounts', 'reference_prefix', 'invoice_terms']);
        });
    }
};
