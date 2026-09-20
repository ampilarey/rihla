<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visa applications — §5.4a, and deliberately **not** Nusuk permits [R-4].
 *
 * They are different authorisations, from different systems, with different
 * failure modes. Under the 2026 rules a traveller can hold a valid visa and
 * still be barred from the Mataf and the Rawdah without a Nusuk permit. One
 * combined status field cannot represent that state — and that is precisely
 * the state that strands a pilgrim at the door.
 *
 * So there is no shared "authorisation" table here and no shared status
 * column. Permits get their own tables in the next slice, and travel
 * readiness is computed from both rather than stored anywhere.
 *
 * **Re-application after rejection is a first-class path**, not an edit of
 * the rejected record. A refusal is a fact about a particular submission —
 * its date, its reference, its reason — and overwriting it to try again
 * destroys the only evidence of what was actually sent. Attempt 2 is a new
 * row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visa_applications', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete on both: a visa application is a record of
            // something submitted to a government, and it must not vanish
            // with the booking it was made for.
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('traveller_id')->constrained()->restrictOnDelete();

            // 1, 2, 3… A rejection does not reopen an application; it ends
            // one, and the next try is a new row with the next number.
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->string('status', 20)->default('not_started');

            // Which visa was applied for. The permitted list is in
            // config/visa.php, because §5.4b requires every Saudi rule to be
            // configuration rather than a constant — a mid-season change to
            // what counts as a valid visa for Umrah is a config edit.
            $table->string('visa_type', 30)->nullable();

            // The reference the issuing system gave back.
            $table->string('reference', 60)->nullable();

            // The officer this one belongs to. Work with no name against it
            // is work nobody does.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // One row per attempt per traveller per booking.
            $table->unique(['booking_id', 'traveller_id', 'attempt']);

            // The two queries operations actually runs: what is stuck, and
            // what is mine.
            $table->index(['status', 'submitted_at']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('visa_application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visa_application_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            // Null for something the system did. A stage that advanced on a
            // timer has no author, and putting a name against it would be a
            // lie in the one record whose job is not to lie.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // §5.4a: every stage carries its evidence. The scan of the visa,
            // the refusal letter, the submission receipt — whatever was
            // actually seen, versioned in the wallet.
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason')->nullable();

            // Append-only, like audit_logs and booking_status_transitions.
            $table->timestamp('created_at')->nullable();

            $table->index(['visa_application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visa_application_events');
        Schema::dropIfExists('visa_applications');
    }
};
