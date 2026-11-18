<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A package's and a staff profile's address is unique within its company —
 * Phase 1e of docs/UMRAH_COMPANIES_PLAN.md.
 *
 * `packages.slug` and `people.slug` were unique across the table, so the
 * first company to call a package `ramadan-umrah` took the address from
 * every other company's own site. Each company's pages read their own rows
 * only (the 1b scope), so the address only has to be unique there.
 *
 * `(slug, company_id)`, slug first, because every lookup is by slug. Added
 * before the old index goes; neither column carries a foreign key, and both
 * are plain index operations, so SQLite does not rebuild either table.
 *
 * Down restores the global rule, and fails loudly if two companies have
 * meanwhile used the same address.
 */
return new class extends Migration
{
    private const TABLES = ['packages', 'people'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unique(['slug', 'company_id']);
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->dropUnique(['slug']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unique(['slug']);
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->dropUnique(['slug', 'company_id']);
            });
        }
    }
};
