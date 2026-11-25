<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notices e-mailed to the customer — `notices:email`.
 *
 * Its own table rather than columns on `notices`: PackageDepartureTest drops
 * and re-creates `notices` from its first migration, and a column added to
 * it later would quietly be missing afterwards (AGENTS.md). A plain indexed
 * pointer, no foreign key, as elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_emails', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('notice_id')->unique();
            $table->string('address', 190);
            $table->timestamp('sent_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_emails');
    }
};
