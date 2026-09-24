<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rates, blocked dates and the stays themselves — §15.4 (Phase 9.2).
 *
 * The second slice of the Stays engine. Phase 9.1 said what is being sold;
 * this says what it costs on a given night, when it is not for sale, and
 * who has taken it.
 *
 * `stays` arrives here rather than in the booking-flow slice for one
 * reason: the no-double-booking invariant counts held and confirmed stays
 * overlapping a night, and an invariant with nothing to count cannot be
 * tested. The public flow — the deposit link, the expiry notice, the Stays
 * board — is the next slice. What lands here is the arithmetic and the
 * lock.
 *
 * ## Dates are half-open
 *
 * `check_in` is a night slept; `check_out` is not. A stay from the 3rd to
 * the 5th occupies the 3rd and the 4th, and somebody else may check in on
 * the 5th. Written down because the off-by-one in the other direction
 * double-books every changeover day in the calendar, and both readings look
 * equally natural in a column name.
 *
 * ## There is no CHECK constraint here, and that is not an oversight
 *
 * `departures` carries `capacity_held + capacity_confirmed <= capacity_total`
 * as a database backstop under its row lock (see
 * `add_the_departure_capacity_constraint`). No equivalent exists for a room
 * type, because the rule is not about one row: it is "for every night in
 * this range, the number of *other* stays overlapping it is below the
 * quantity". A CHECK sees one row and cannot count its neighbours.
 *
 * So the `SELECT … FOR UPDATE` on the room type is not the first of two
 * guards here — it is the only one. That is what makes the MySQL
 * concurrency test in `StayLockTest` the whole guarantee rather than a
 * confirmation of it, and why every write path must go through
 * App\Services\Stays\StayAllocator.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Seasonal overrides. A guesthouse quotes December differently from
        // May and neither is "the" price, so this is a table rather than
        // more columns on the room type.
        Schema::create('rates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();

            // Inclusive both ends: a season runs from the 1st to the 31st
            // and a guesthouse owner reading the admin screen means both.
            // The half-open rule above governs a *stay*, not a season, and
            // conflating the two is how the last night of December gets
            // charged at the May rate.
            $table->date('starts_on');
            $table->date('ends_on');

            $table->unsignedBigInteger('rate_minor');

            // Overrides the property's own minimum for this season — a
            // guesthouse that takes two nights in May may want five over
            // new year. Null means the property's figure stands.
            $table->unsignedSmallInteger('min_nights')->nullable();

            $table->timestamps();

            $table->index(['room_type_id', 'starts_on', 'ends_on']);
        });

        // A night this room is not for sale: the partner has taken it back,
        // it is being repaired, or an iCal feed said so.
        Schema::create('blocked_dates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->string('source', 20)->default('admin');
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // One block per room per night. Blocking a night twice is not a
            // second block, and without this a partner sync that ran twice
            // would leave the calendar reading as if it had.
            $table->unique(['room_type_id', 'date']);
        });

        Schema::create('stays', function (Blueprint $table): void {
            $table->id();

            $table->string('reference')->nullable()->unique();

            // Restricted, all three. A stay is a commercial record: the
            // customer who booked it, the building they are going to, and
            // the room they were sold. None of those may vanish underneath
            // it, and deleting a property with stays against it should be
            // refused rather than quietly cascade a guest's booking away.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();

            // Half-open: check_in is slept, check_out is not.
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('nights');

            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);

            $table->string('currency', 3)->default('USD');

            // What was quoted, night by night, at the moment it was quoted.
            // A rate changed afterwards must not move a stay somebody has
            // already agreed to — the plan asks for this by name.
            $table->json('rate_snapshot')->nullable();

            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('deposit_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);

            $table->string('status', 20)->default('requested');

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('partner_confirmed_at')->nullable();
            $table->timestamp('deposit_due_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            $table->text('special_requests')->nullable();
            $table->string('source', 40)->nullable();

            $table->timestamps();

            // The index the invariant reads on every hold: which stays
            // against this room overlap these nights, and are they still
            // holding the dates. Status leads the date columns because the
            // query filters it to exactly two values first.
            $table->index(['room_type_id', 'status', 'check_in', 'check_out'], 'stays_occupancy_index');
            $table->index(['property_id', 'status']);
            $table->index(['customer_id', 'status']);
            // The expiry sweep: holds whose clock has run out.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
        Schema::dropIfExists('blocked_dates');
        Schema::dropIfExists('rates');
    }
};
