<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel agency A1 — docs/TRAVEL_AGENCY_PLAN.md. "Plan my trip": the
 * customer says where, when and who; staff quote one or more options with
 * an expiry; the customer accepts one online and pays for it.
 *
 * Plain indexed columns for every pointer, as in Transport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('status_key', 64)->unique();
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->boolean('wants_flight')->default(false);
            $table->boolean('wants_hotel')->default(false);
            $table->boolean('wants_visa')->default(false);
            $table->string('origin', 80)->nullable();
            $table->string('destination', 120);
            $table->date('depart_on');
            $table->date('return_on')->nullable();
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            $table->string('contact_name', 120);
            $table->string('contact_phone', 30);
            $table->string('contact_email', 190)->nullable();
            $table->text('notes')->nullable();
            $table->char('currency', 3)->default('MVR');
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('quote_expires_at')->nullable();
            $table->unsignedBigInteger('quoted_by')->nullable()->index();
            $table->unsignedBigInteger('accepted_option_id')->nullable()->index();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('total_minor')->nullable();
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->text('cancelled_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('travel_quote_options', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('travel_request_id')->index();
            $table->string('title', 160);
            $table->text('details')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);
            $table->timestamps();
        });

        Schema::create('travel_request_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('travel_request_id')->index();
            $table->string('status', 20);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_request_events');
        Schema::dropIfExists('travel_quote_options');
        Schema::dropIfExists('travel_requests');
    }
};
