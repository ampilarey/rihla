<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company's site on a domain of its own — companies plan, Phase 4e
 * (decision 5). The company points its domain at the server, Rihla's admin
 * adds it in cPanel with a certificate and writes it here; the application
 * then maps that host to the company exactly as it maps `coral.rihla.mv`.
 * Stored bare and lower-case (`coralumrah.mv`); `www.` is answered too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->string('site_domain', 253)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->dropUnique(['site_domain']);
        });

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn('site_domain');
        });
    }
};
