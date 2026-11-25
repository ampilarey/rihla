<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel agency A2 — docs/TRAVEL_AGENCY_PLAN.md. The e-tickets, hotel
 * vouchers and visas staff issue for a paid trip, encrypted on the
 * documents disk as passports are (decision 5), and handed to the
 * customer on their trip page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('travel_request_id')->index();
            $table->string('kind', 20); // ticket, voucher, visa, other
            $table->string('label', 160);
            $table->string('disk', 40);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_documents');
    }
};
