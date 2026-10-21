<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document reading — batch B from Hijaz.
 *
 * A reading is what a reader proposed for one uploaded file and what staff
 * then accepted; append-only, a second reading is a second row. The
 * traveller gains the ID card's number and the record of where the card
 * and the passport disagree; a payment gains what the reader made of its
 * slip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->string('reader', 20);
            $table->string('model', 60)->nullable();
            $table->json('fields')->nullable();
            $table->json('confidence')->nullable();
            $table->text('raw_text')->nullable();
            $table->boolean('mrz_valid')->nullable();
            $table->string('status', 12)->default('proposed');
            $table->json('accepted_fields')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['status', 'created_at']);
        });

        Schema::table('travellers', function (Blueprint $table): void {
            $table->string('national_id', 20)->nullable()->after('relationship');
            $table->json('identity_mismatches')->nullable()->after('emergency_contact_phone');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->json('slip_reading')->nullable()->after('slip_checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');

        Schema::table('travellers', function (Blueprint $table): void {
            $table->dropColumn(['national_id', 'identity_mismatches']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('slip_reading');
        });
    }
};
