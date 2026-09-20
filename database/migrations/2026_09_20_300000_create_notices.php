<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things a customer needs to be told — the notifications §11.2 lists.
 *
 * ## Recorded, shown, and chased by a person
 *
 * This codebase already records, at §6.4 of the plan, that "there is no
 * notification channel, and this does not pretend otherwise". That is still
 * true: no WhatsApp Business API, no SMTP, no SMS provider.
 *
 * So a notice is not a queued message. It is a **row saying somebody needs
 * to know something**, which does three honest things today:
 *
 * - it appears on the Pilgrim Portal, which needs no credentials;
 * - it gives staff a list of who has not been told, instead of a
 *   spreadsheet nobody updates;
 * - it carries a prepared WhatsApp link so the conversation staff were
 *   going to have anyway takes one tap.
 *
 * That is the same shape the waitlist claim link already uses, and for the
 * same reason: a Mailable posting into the log driver would look finished
 * and reach nobody.
 *
 * ## `seen_at` and `handled_at` are different facts
 *
 * `seen_at` is the customer opening their portal. `handled_at` is a member
 * of staff saying they have dealt with it. Conflating them would let a
 * notice disappear from the staff queue because the customer happened to
 * load a page, which is how a passport request goes unchased for a
 * fortnight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();

            // Cascade: a notice about a booking that no longer exists is
            // not about anything.
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // 'booking_confirmed', 'payment_recorded', 'document_needed',
            // 'document_rejected', 'visa_issued', 'permit_issued',
            // 'departure_soon'. A closed list, because each one is raised
            // from a record that already exists — there is no notice for
            // anything this system cannot observe.
            $table->string('kind', 40);

            $table->string('headline');
            $table->text('body')->nullable();

            // The customer has opened their portal since it was raised.
            $table->timestamp('seen_at')->nullable();

            // A member of staff has dealt with it. Deliberately not the
            // same fact as the customer having seen it.
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One of each kind per booking at a time, so a nightly sweep
            // does not stack fourteen identical passport reminders. A
            // notice that is handled can be raised again.
            $table->index(['booking_id', 'kind', 'handled_at']);
            $table->index(['handled_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
