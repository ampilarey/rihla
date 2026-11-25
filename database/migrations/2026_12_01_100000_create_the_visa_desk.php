<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel agency A3 — docs/TRAVEL_AGENCY_PLAN.md: the visa desk for other
 * countries.
 *
 * - `visa_products`: the list staff keep (decision 2) — country, visa type,
 *   the documents needed, the usual processing days and the fee.
 * - `travel_visa_applications`: one applicant's visa on a trip request,
 *   with its checklist and its status (collecting, submitted, approved,
 *   refused).
 * - `travel_documents` learns which application a file was collected for,
 *   and whether the customer sent it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visa_products', function (Blueprint $table): void {
            $table->id();
            $table->string('country', 80);
            $table->string('visa_type', 80);
            $table->json('documents');
            $table->unsignedSmallInteger('processing_days')->nullable();
            $table->unsignedBigInteger('fee_minor')->nullable();
            $table->char('currency', 3)->default('MVR');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['country', 'visa_type'], 'visa_products_country_type_unique');
        });

        Schema::create('travel_visa_applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('travel_request_id')->index();
            $table->unsignedBigInteger('visa_product_id')->index();
            $table->string('applicant_name', 120);
            $table->string('status', 20)->index();
            $table->json('checklist');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('opened_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('travel_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('visa_application_id')->nullable()->index();
            $table->boolean('from_customer')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('travel_documents', function (Blueprint $table): void {
            $table->dropIndex(['visa_application_id']);
        });

        Schema::table('travel_documents', function (Blueprint $table): void {
            $table->dropColumn(['visa_application_id', 'from_customer']);
        });

        Schema::dropIfExists('travel_visa_applications');
        Schema::dropIfExists('visa_products');
    }
};
