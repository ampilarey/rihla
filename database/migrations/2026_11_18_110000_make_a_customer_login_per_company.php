<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One login can be a customer of more than one company — Phase 1c of
 * docs/UMRAH_COMPANIES_PLAN.md.
 *
 * `customers.user_id` was unique across the whole table, so somebody who
 * booked with Rihla could never be a customer of a second company: the
 * plan says the same person at two companies is two unrelated customer
 * records. It becomes unique per company instead.
 *
 * **The new index is added before the old one goes, and leads with
 * `user_id`.** MySQL requires an index on a constrained column: the
 * foreign key to `users` rests on `customers_user_id_unique`, and dropping
 * it with nothing else covering `user_id` is refused with errno 1553
 * (AGENTS.md). `(user_id, company_id)` covers it and means the same thing.
 * Both are plain index operations, so SQLite does not rebuild the table.
 *
 * Down restores the global rule, and fails loudly if one login has meanwhile
 * become a customer of two companies, which is the right answer: that state
 * cannot be squeezed back into one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unique(['user_id', 'company_id']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unique(['user_id']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'company_id']);
        });
    }
};
