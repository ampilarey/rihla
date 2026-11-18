<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A departure can be saved, and take registrations at the office, before
 * its dates are fixed — companies plan, Phase 3b (Hijaz parity).
 *
 * The airline confirms the flights months after the office starts taking
 * names. Until then the dates are empty, not invented: an invented date is
 * read by the readiness board, the passport check and the public site as a
 * real one. A departure without dates never reaches the public site and
 * cannot leave on its journey; once its flights are entered, they set the
 * dates.
 *
 * **Not `change()` on SQLite.** Laravel changes a SQLite column by building
 * a new table and dropping the old one, and dropping `departures` with
 * foreign keys enforced deletes every row that cascades from it (price
 * tiers, flights, hotels...) and every trigger on it, the seat-capacity
 * rule among them. `PackageDepartureTest` found both. Removing a NOT NULL is
 * one of the changes SQLite documents as safe to make by editing the stored
 * schema ("Making Other Kinds Of Table Schema Changes"), with no rebuild at
 * all, so that is what happens there. MySQL changes the column in place and
 * keeps its rows and its CHECK constraint.
 */
return new class extends Migration
{
    private const STRICT = ['"date_start" date not null', '"date_end" date not null'];

    private const LOOSE = ['"date_start" date', '"date_end" date'];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->editSqliteSchema(self::STRICT, self::LOOSE);

            return;
        }

        Schema::table('departures', function (Blueprint $table): void {
            $table->date('date_start')->nullable()->change();
            $table->date('date_end')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A departure still without dates cannot be squeezed back into a
        // column that requires them; refuse loudly rather than invent one.
        if (DB::table('departures')->whereNull('date_start')->orWhereNull('date_end')->exists()) {
            throw new RuntimeException('Some departures have no dates yet. Give them dates before rolling this back.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->editSqliteSchema(self::LOOSE, self::STRICT);

            return;
        }

        Schema::table('departures', function (Blueprint $table): void {
            $table->date('date_start')->nullable(false)->change();
            $table->date('date_end')->nullable(false)->change();
        });
    }

    /**
     * Rewrite the stored CREATE TABLE text for `departures`, as SQLite
     * documents for constraint-only changes, and bump the schema version so
     * every connection re-reads it.
     *
     * @param  list<string>  $from
     * @param  list<string>  $to
     */
    private function editSqliteSchema(array $from, array $to): void
    {
        $sql = (string) DB::selectOne("select sql from sqlite_master where type = 'table' and name = 'departures'")?->sql;
        $edited = str_replace($from, $to, $sql);

        if ($edited === $sql) {
            throw new RuntimeException('The departures table is not in the shape this migration expects; nothing was changed.');
        }

        $version = (int) DB::selectOne('PRAGMA schema_version')?->schema_version;

        DB::statement('PRAGMA writable_schema = ON');
        DB::update("update sqlite_master set sql = ? where type = 'table' and name = 'departures'", [$edited]);
        DB::statement('PRAGMA schema_version = '.($version + 1));
        DB::statement('PRAGMA writable_schema = OFF');
    }
};
