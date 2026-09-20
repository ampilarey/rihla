<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telling everybody at once — §6.5's "group-wide emergency broadcast".
 *
 * ## Who it reached is the point
 *
 * "We told everybody" is worthless without a list. `broadcast_deliveries`
 * records one row per person per channel with what happened to it, so the
 * question after an incident — *did her family actually get this?* — has an
 * answer rather than an assumption. That is the difference between a
 * broadcast and a button.
 *
 * ## Channels, honestly
 *
 * The only channel that works on this host today is the portals: a notice
 * appears on the Pilgrim Portal and the Family Portal with no credentials
 * at all. Email needs SMTP, which is `log` here and nobody has supplied
 * real settings; SMS needs a provider nobody has chosen. Both are recorded
 * as *unavailable* rather than as sent, because a delivery row saying
 * "sent" for a message that went to a log file is worse than no row.
 *
 * ## Sending is its own act, and it is stamped
 *
 * `sent_at` is separate from `created_at` so a draft is a draft. An
 * emergency broadcast cannot be unsent, and the screen says so before it
 * goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_broadcasts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();

            // The incident it is about, when there is one. Nullable: "the
            // group is safe, ignore the news" is a broadcast with no
            // incident behind it.
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();

            $table->string('headline');
            $table->text('body');

            // Separate from created_at: a draft is a draft, and this cannot
            // be unsent.
            $table->timestamp('sent_at')->nullable();

            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['departure_id', 'sent_at']);
        });

        Schema::create('broadcast_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('emergency_broadcast_id')->constrained()->cascadeOnDelete();

            // Who it was for. A booking rather than a traveller: the portal
            // and the family links hang off a booking, and that is what a
            // message actually reaches.
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // 'portal', 'email', 'sms'.
            $table->string('channel', 20);

            // 'delivered', 'unavailable', 'failed'. No 'sent': either it
            // reached somewhere a person will see it, or it did not, and a
            // row that claims the middle is how "we told everybody" becomes
            // a thing nobody can check.
            $table->string('status', 20);

            // Why not, in words, for the person reading this after the
            // fact rather than for a log parser.
            $table->string('detail')->nullable();

            $table->timestamps();

            // One row per booking per channel per broadcast. A retry
            // updates rather than adding a second, so the list stays a list
            // of people rather than a list of attempts.
            $table->unique(['emergency_broadcast_id', 'booking_id', 'channel'], 'broadcast_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_deliveries');
        Schema::dropIfExists('emergency_broadcasts');
    }
};
