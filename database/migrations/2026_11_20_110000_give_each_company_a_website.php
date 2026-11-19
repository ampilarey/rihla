<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each Umrah company's own website — companies plan, Phase 4a.
 *
 * Two switches on the company, both Rihla's admin's: whether its site may
 * be served at all (standing for "the registration fee is paid" until
 * Phase 5 ties the two), and whether its pages say "Powered by Rihla"
 * (decision 8: off unless switched on).
 *
 * Everything the company chooses itself — theme, logo, colours, words,
 * sections and whether the site is published — is on `company_sites`, one
 * row per company. `company_id` is a plain indexed column, as on every
 * Umrah table, for the SQLite rebuild trap in AGENTS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->boolean('site_enabled')->default(false);
            $table->boolean('powered_by_rihla')->default(false);
        });

        Schema::create('company_sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->string('theme', 20)->default('classic');
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('colour_primary', 7)->nullable();
            $table->string('colour_accent', 7)->nullable();
            $table->string('font', 20)->default('inter');
            $table->json('tagline')->nullable();
            $table->json('about')->nullable();
            $table->json('sections')->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('instagram')->nullable();
            $table->string('facebook')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_sites');

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn('site_enabled');
        });

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn('powered_by_rihla');
        });
    }
};
