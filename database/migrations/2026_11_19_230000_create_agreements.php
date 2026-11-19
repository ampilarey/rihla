<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The booking agreement (companies plan, Phase 3h, part 3, from Hijaz):
 * signed on screen in the portal, or as a paper copy whose scan is kept
 * with the lead traveller's documents.
 *
 * `wording` is the text as signed, frozen at the moment of signing, so a
 * later edit to the company's terms never changes what somebody agreed to.
 * One agreement per booking; new table, so its foreign keys cost nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('wording')->nullable();
            $table->string('method', 8)->nullable();
            $table->string('signed_name', 160)->nullable();
            $table->mediumText('signature')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
