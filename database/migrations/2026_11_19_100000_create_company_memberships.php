<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Umrah companies and the people who work for them — Phase 2a of
 * docs/UMRAH_COMPANIES_PLAN.md.
 *
 * **`partners.is_umrah_company`.** An Umrah company is a partner, as Phase 1
 * decided, but `kind` cannot say so: `agency` already means a guesthouse
 * business with several places (the host registration form offers it). So
 * a flag, false for every guesthouse. Rihla's own row is set to true, found
 * by its `is_rihla` flag on the day this runs (AGENTS.md: never a constant).
 * A plain boolean added to `partners`, so SQLite adds it in place.
 *
 * **`company_memberships`** joins a user to a company with a role, as
 * `host_memberships` does for a guesthouse. A new table, so its foreign
 * keys cost no rebuild. One person may work for two companies; the same
 * login being a *customer* of a company is unrelated (`customers.user_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->boolean('is_umrah_company')->default(false);
        });

        DB::table('partners')->where('is_rihla', true)->update(['is_umrah_company' => true]);

        Schema::create('company_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_memberships');

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn('is_umrah_company');
        });
    }
};
