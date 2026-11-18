<?php

use App\Support\OfficeContact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the office deals with — companies plan, Phase 3a (Hijaz parity).
 *
 * The head of a family holds its papers and is rung on the journey; the
 * person who deals with the office (pays, signs, gets the messages) is
 * often somebody else. `customers.contact_traveller_id` names that person
 * for the family; `bookings.contact_traveller_id` names a different one
 * for one trip. Null on both means the person who booked, as until now.
 *
 * Plain indexed columns with no foreign key: a constrained column added to
 * `customers` or `bookings` makes SQLite rebuild the table and cascade-delete
 * what hangs off it (AGENTS.md). A contact who is no longer one of the
 * family's travellers is simply not used ({@see OfficeContact}).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'bookings'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('contact_traveller_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'bookings'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['contact_traveller_id']);
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('contact_traveller_id');
            });
        }
    }
};
