<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nusuk permits — §5.4b, and a different authorisation from a visa [R-4].
 *
 * Under the 2026 rules a traveller can hold a valid visa and still be barred
 * from the Mataf and the Rawdah without a permit. That is why this is its
 * own table with its own status rather than more columns on
 * `visa_applications`: one combined field cannot express "visa issued, still
 * barred", and that is exactly the state that strands a pilgrim at the door.
 *
 * **The Umrah permit and a Rawdah slot are separate records**, not one
 * record with two dates. They are granted separately, refused separately,
 * and a missing Rawdah slot is a disappointment while a missing Umrah permit
 * is a wasted journey. Treating them as one field would make those two
 * failures indistinguishable.
 *
 * The prerequisite gate lives on `departures`: Nusuk wants accommodation and
 * transport recorded before a permit can be requested, and both are
 * properties of the dated run rather than of a person. What is stored is
 * *when Rihla recorded them in Nusuk* — the compliance judgement is Nusuk's,
 * and this application does not pretend to make it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nusuk_permits', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete on both: a permit is a record of a dealing
            // with a Saudi system and must not vanish with a booking.
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('traveller_id')->constrained()->restrictOnDelete();

            // 'umrah' or 'rawdah'. See the class docblock: separate records,
            // deliberately.
            $table->string('kind', 20);

            // A Rawdah slot can be rebooked and an Umrah permit re-requested,
            // so attempts are numbered the way visa applications are.
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->string('status', 20)->default('not_started');

            $table->string('reference', 60)->nullable();

            // When the visit is actually booked for. A Rawdah slot is a
            // moment, not a day: it is a timestamp.
            $table->timestamp('slot_at')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->string('refusal_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'traveller_id', 'kind', 'attempt']);
            $table->index(['status', 'requested_at']);
            $table->index(['kind', 'slot_at']);
        });

        Schema::create('nusuk_permit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nusuk_permit_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['nusuk_permit_id', 'created_at']);
        });

        Schema::table('departures', function (Blueprint $table) {
            // The prerequisite gate. Timestamps rather than booleans: "who
            // ticked a box" is worthless next to "when was this actually
            // entered", and the audit trail carries the who.
            //
            // These record that *Rihla recorded them in Nusuk*. Whether what
            // was entered is compliant is Nusuk's judgement, and this
            // application does not claim to make it.
            $table->timestamp('nusuk_accommodation_recorded_at')->nullable()->after('status');
            $table->timestamp('nusuk_transport_recorded_at')->nullable()->after('nusuk_accommodation_recorded_at');
        });
    }

    public function down(): void
    {
        Schema::table('departures', function (Blueprint $table) {
            $table->dropColumn(['nusuk_accommodation_recorded_at', 'nusuk_transport_recorded_at']);
        });

        Schema::dropIfExists('nusuk_permit_events');
        Schema::dropIfExists('nusuk_permits');
    }
};
