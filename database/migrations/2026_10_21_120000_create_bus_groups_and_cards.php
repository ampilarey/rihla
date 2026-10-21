<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buses, who sits on which, and the cards and tags they carry — Hijaz's
 * ground operations, brought across (batch A3 of the comparison).
 *
 * `booking_travellers.bus_group_id` is a plain indexed column and not a
 * foreign key, on purpose. Adding a *constrained* column makes SQLite
 * rebuild the table, and the rebuild cascade-deletes every row hanging off
 * it — AGENTS.md records it biting twice. `BusGroup`'s `deleting` hook
 * does the referential work instead, the way `Partner` does for
 * `packages.partner_id`.
 *
 * `cards.qr_token` is 64 wide rather than the 40 characters issued, because
 * the scrubber's stand-in for a token is 64 hex characters and a column
 * too short for it fails `data:anonymise` on the first card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('leader_name', 80)->nullable();
            $table->string('leader_phone', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['departure_id', 'name']);
        });

        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->unsignedBigInteger('bus_group_id')->nullable()->after('seat_number')->index();
            $table->timestamp('checked_in_at')->nullable()->after('bus_group_id');
        });

        Schema::create('cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_traveller_id')->constrained('booking_travellers')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('serial', 20)->unique();
            $table->string('qr_token', 64)->unique();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->unsignedInteger('printed_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['booking_traveller_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');

        // The index, then the column, each in its own call (AGENTS.md).
        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->dropIndex(['bus_group_id']);
        });

        Schema::table('booking_travellers', function (Blueprint $table): void {
            $table->dropColumn(['bus_group_id', 'checked_in_at']);
        });

        Schema::dropIfExists('bus_groups');
    }
};
