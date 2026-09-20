<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Family Portal — §6.2.
 *
 * "Families at home are the strongest referral channel Rihla has… **with
 * privacy controls the pilgrim owns** (no individual live location unless
 * explicitly enabled; group-level status only by default)."
 *
 * That sentence is the whole design, and it is built as a separate table
 * rather than a flag on `portal_accesses` for one reason: a family token
 * must never be accepted anywhere a pilgrim token is. One table with a
 * `kind` column is one forgotten `where` away from a mother-in-law reading
 * a passport number.
 *
 * ## The pilgrim issues it, and the pilgrim revokes it
 *
 * Not staff. §6.2 says the controls are the pilgrim's, so the link is
 * minted from the Pilgrim Portal by whoever holds the booking. Staff can
 * see that one exists; they cannot mint one, and nothing shows the
 * plaintext twice.
 *
 * ## Group-level by default, and that is a column that starts false
 *
 * `shares_attendance` is the only individual thing a family can be shown,
 * and it defaults to off. It shares *that the traveller was accounted for
 * at a head count* — not where they are. There is no location column here
 * and there is not going to be one until somebody asks for it in writing:
 * an absent default is the only kind that cannot be turned on by accident.
 *
 * ## Announcements are the group-level content
 *
 * Written by staff against a departure, read by the family and by the
 * pilgrim. Published separately from created so a draft written at 3am is
 * not visible until somebody means it to be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_accesses', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete, matching portal_accesses: deleting a
            // booking out from under a live link would leave somebody's
            // family with a page that 500s rather than one that explains.
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();

            // Only the hash. The plaintext exists once, in the response
            // that created it. A system that can show you the link again is
            // one that can be made to show it to somebody else.
            $table->string('token_hash', 64)->unique();

            // "Mum", "the family group". So a pilgrim revoking one of three
            // links knows which is which.
            $table->string('label', 60)->nullable();

            // The one individual thing, and it starts off. It shares that
            // the traveller was accounted for at a head count — not where
            // they are.
            $table->boolean('shares_attendance')->default(false);

            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('uses')->default(0);

            $table->timestamps();

            $table->index(['booking_id', 'expires_at']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();

            $table->string('headline');
            $table->text('body')->nullable();

            // Separate from created_at, so a draft written at three in the
            // morning is not on a family's screen until somebody means it
            // to be.
            $table->timestamp('published_at')->nullable();

            $table->foreignId('written_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['departure_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('family_accesses');
    }
};
