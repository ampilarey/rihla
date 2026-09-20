<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidents on the ground — §8.3, and the minimum §6.5 asks for.
 *
 * ## Three severities, each with a stated meaning
 *
 * Not a five-point scale. A scale nobody has defined gets used as a mood
 * ring: everything is a 3 until something goes badly wrong and then
 * everything is a 5. These three can be told apart by the person typing:
 *
 * - `minor` — handled on the spot, recorded so it is not lost;
 * - `serious` — the office needs to know today;
 * - `emergency` — somebody needs to act now. Medical, a missing person, a
 *   lost passport.
 *
 * ## It is always about a departure, and sometimes about a person
 *
 * `traveller_id` is nullable because a coach that does not arrive is an
 * incident with no victim, and forcing a name onto it would mean somebody
 * picks one at random.
 *
 * ## Recorded, never overwritten
 *
 * The narrative lives in `incident_notes` — append-only, each with an
 * author and a time. An incident report that can be quietly rewritten after
 * the fact is not evidence, and this is the record that gets read if
 * anything ever reaches a lawyer or a regulator. The same reasoning as
 * [R-8]'s supersede-never-overwrite on documents.
 *
 * ## Who is on it
 *
 * `assigned_to` and `resolved_at` are the two columns that make this a
 * queue rather than a diary. An open emergency with nobody assigned is
 * exactly what a screen should be able to find.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 40)->nullable()->unique();

            // Cascade: an incident on a departure that is deleted has
            // nothing left to be about. Departures are not deleted in
            // practice — this is the honest shape rather than a plan.
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();

            // A coach that does not arrive has no victim. Restrict rather
            // than cascade: deleting a traveller must not silently take the
            // record of what happened to them with it.
            $table->foreignId('traveller_id')->nullable()->constrained()->restrictOnDelete();

            // 'minor', 'serious', 'emergency'.
            $table->string('severity', 20);

            // 'medical', 'lost_document', 'missing_person', 'transport',
            // 'accommodation', 'conduct', 'other'. A closed list so the same
            // thing is not filed under four spellings, with 'other' so
            // nobody has to force a fit.
            $table->string('category', 30);

            $table->string('summary');
            $table->text('detail')->nullable();

            // When it happened, which is not when somebody got round to
            // typing it. On a trip those are routinely hours apart.
            $table->timestamp('happened_at');

            $table->string('location')->nullable();

            // 'open', 'resolved'. Two states: it is being dealt with, or it
            // is done and somebody said how. "In progress" is what
            // `assigned_to` already means.
            $table->string('status', 20)->default('open');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();

            // Who typed it. nullOnDelete so a staff account closing does not
            // delete what they recorded.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The two questions a screen asks: what is still open on this
            // departure, and what is open anywhere.
            $table->index(['departure_id', 'status']);
            $table->index(['status', 'severity']);
        });

        Schema::create('incident_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');

            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_notes');
        Schema::dropIfExists('incidents');
    }
};
