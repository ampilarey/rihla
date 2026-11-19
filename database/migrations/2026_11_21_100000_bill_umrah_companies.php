<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Umrah companies (companies plan, "Later": billing automation).
 *
 * Rihla's invoices *to* a company: its registration fee once, and its
 * monthly fee for each calendar month from the month billing starts. Paid
 * by bank transfer (decision 14: no cards through Rihla's BML), so a
 * payment is recorded by Rihla's admin, never collected here.
 *
 * `partner_id` is a plain indexed column, as `company_id` is everywhere
 * else: no foreign key, so nothing here joins the SQLite-rebuild cascade
 * AGENTS.md records, and a company is never deleted anyway (Phase 5d closes
 * one instead). Not `company_id`: that column means "this record belongs to
 * the company's own books" and is scoped to it; these are Rihla's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->unsignedBigInteger('monthly_fee_minor')->nullable()->after('registration_paid_at');
            $table->string('monthly_fee_currency', 3)->nullable()->after('monthly_fee_minor');
            $table->date('billing_starts_on')->nullable()->after('monthly_fee_currency');
        });

        Schema::create('company_invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id')->index();
            $table->string('number', 30)->unique();
            $table->string('kind', 20);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->date('issued_on');
            $table->date('due_on');
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason', 500)->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            // One invoice per company, kind and month. Named: the generated
            // name is 56 characters today and one rename from MySQL's 64.
            $table->unique(['partner_id', 'kind', 'period_start'], 'company_invoices_one_per_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invoices');

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn(['monthly_fee_minor', 'monthly_fee_currency', 'billing_starts_on']);
        });
    }
};
