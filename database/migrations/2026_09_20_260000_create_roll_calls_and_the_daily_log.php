<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance and the daily operations log — the rest of §8.3.
 *
 * ## A roll call is a moment, not a day
 *
 * "Attendance" on an Umrah group is not a register taken each morning. It
 * is a head count at the moments where somebody can actually be left
 * behind: boarding at Velana, off the coach at the hotel, back from the
 * Haram before a transfer. So a roll call is named after its moment and has
 * a time, and there are several in a day or none.
 *
 * ## An unmarked traveller is not present
 *
 * The whole point. A roll call with nine of eleven marked is not
 * "nine present" — it is a count that has not been finished, and the two
 * nobody marked are exactly the two to go and look for. There is no
 * default state and no row is written until somebody marks it, so a missing
 * row means missing, not fine.
 *
 * That is why `roll_call_marks` has no default on `state` and why the
 * screen counts against the departure's confirmed travellers rather than
 * against the rows in this table.
 *
 * ## Excused is a third state and not a kind of absent
 *
 * Somebody who stayed at the hotel with a fever, with the leader's
 * knowledge, is not missing. Folding the two together means the screen
 * cries wolf on every trip and stops being read.
 *
 * ## The daily log is not an incident
 *
 * `operations_log_entries` is what happened, ordinarily: the coach was
 * forty minutes late, the hotel moved the group to the third floor. Things
 * that went *wrong* are `incidents` and have a severity, an owner and a
 * resolution. Keeping them apart is what stops the incident list filling
 * with weather.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_calls', function (Blueprint $table) {
            $table->id();

            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();

            // "Boarding at Velana", "Off the coach in Madinah". Free text,
            // because the moments that matter differ by itinerary and a
            // fixed list would be wrong for the first trip that needs a
            // different one.
            $table->string('moment');

            // When the count was taken, which is not when it was typed up.
            $table->timestamp('taken_at');

            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['departure_id', 'taken_at']);
        });

        Schema::create('roll_call_marks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('roll_call_id')->constrained()->cascadeOnDelete();

            // Restrict: deleting a traveller must not silently remove the
            // record that they were or were not there.
            $table->foreignId('traveller_id')->constrained()->restrictOnDelete();

            // 'present', 'absent', 'excused'. **No default.** A traveller
            // with no row is unmarked, and unmarked is not present — it is
            // the person to go and look for.
            $table->string('state', 20);

            $table->string('note')->nullable();

            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One mark per person per count. Two rows for the same person
            // would make the arithmetic quietly wrong.
            $table->unique(['roll_call_id', 'traveller_id']);
        });

        Schema::create('operations_log_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();

            // The day it is about, separate from when it was written. A log
            // written up the next morning is still about yesterday.
            $table->date('happened_on');

            $table->text('body');

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['departure_id', 'happened_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_log_entries');
        Schema::dropIfExists('roll_call_marks');
        Schema::dropIfExists('roll_calls');
    }
};
