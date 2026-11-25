<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargo and charters — docs/TRANSPORT_PLAN.md (T4). Nothing here has a
 * timetable or a price list: somebody asks, staff quote, the asker pays,
 * and staff move it along until it is delivered or done.
 *
 * Links to customers, partners, islands and users are plain indexed
 * columns, as everywhere else in Transport (see the SQLite rebuild traps
 * in AGENTS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique();
            // The asker's key to their status page; the reference is counted.
            $table->string('status_key', 64)->unique();
            $table->string('kind', 10)->index(); // cargo, charter
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('partner_id')->nullable()->index();
            $table->unsignedBigInteger('origin_island_id')->nullable()->index();
            $table->unsignedBigInteger('destination_island_id')->nullable()->index();
            $table->date('wanted_on');
            $table->string('contact_name', 120);
            $table->string('contact_phone', 30);
            $table->unsignedSmallInteger('passengers')->nullable();
            $table->text('cargo_description')->nullable();
            $table->unsignedInteger('cargo_weight_kg')->nullable();
            $table->text('notes')->nullable();
            $table->char('currency', 3)->default('MVR');
            $table->unsignedBigInteger('quote_minor')->nullable();
            $table->text('quote_note')->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->unsignedBigInteger('quoted_by')->nullable()->index();
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->text('cancelled_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('transport_request_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transport_request_id')->index();
            $table->string('status', 20);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_request_events');
        Schema::dropIfExists('transport_requests');
    }
};
