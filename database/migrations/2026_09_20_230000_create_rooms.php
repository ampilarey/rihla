<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms, and who is in them — §8.2.
 *
 * The plan is blunt about why this exists: "room allocation and rooming
 * lists are, in practice, where operator time disappears." It is currently
 * a spreadsheet per departure, rebuilt by hand every time somebody cancels.
 *
 * ## A room belongs to a hotel stay, not to a departure
 *
 * A party is in one hotel in Makkah and a different one in Madinah, and the
 * rooming is not the same in both — the Makkah room is four beds and the
 * Madinah room is two. Hanging rooms off `departure_hotels` rather than off
 * `departures` is what lets a traveller have a different room in each city
 * without a second table.
 *
 * ## Capacity is a number, not the occupancy word
 *
 * `occupancy` on a booking is what was *sold* — a quad rate. How many beds
 * are actually in room 412 is a different fact, and the two disagree often
 * enough that conflating them would make the conflict report lie. Both are
 * kept.
 *
 * ## Gender is on the room, and 'family' is a real answer
 *
 * A room designated `family` may legitimately hold a husband, a wife and
 * their children, and a rule that forbade mixed gender everywhere would
 * flag every one of those. A room with no designation is unset rather than
 * mixed: the conflict report says so instead of guessing.
 *
 * ## Nothing here is automatic
 *
 * There is no allocator. §8.2 asks for "intelligent grouping by
 * family/gender/age", and an algorithm that shuffles real pilgrims into
 * rooms on rules nobody has stated is how a mother ends up separated from
 * her children on the strength of a heuristic. What this does is make the
 * mistakes visible: every conflict is detected, named, and left for a
 * person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();

            // The hotel stay this room is part of. cascadeOnDelete: a room
            // has no meaning once the hotel is off the departure, and the
            // assignments go with it.
            $table->foreignId('departure_hotel_id')->constrained()->cascadeOnDelete();

            // What the hotel calls it — "412", "Deluxe 3". Free text,
            // because a hotel's numbering is theirs and normalising it
            // loses the thing staff read off a key card.
            $table->string('label', 40);

            // Beds. Not the sold occupancy: see the class docblock.
            $table->unsignedSmallInteger('capacity');

            // 'male', 'female', 'family', or null for undesignated.
            $table->string('gender', 10)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // One label per hotel stay. Two rooms called 412 in the same
            // hotel is a typo, and it is the kind that puts somebody in the
            // wrong bed.
            $table->unique(['departure_hotel_id', 'label']);
        });

        Schema::create('room_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: a traveller with a room cannot be deleted
            // out from under the rooming list.
            $table->foreignId('traveller_id')->constrained()->restrictOnDelete();

            // Which booking put them there. Kept because a rooming list is
            // read alongside the bookings it came from, and because a party
            // that cancels has to be findable in one query.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One bed per person per room. Being in the same room twice is
            // always a mistake; being in *two* rooms in the same hotel is
            // also a mistake, but it is one the conflict report names
            // rather than one the database can express.
            $table->unique(['room_id', 'traveller_id']);
            $table->index('traveller_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_assignments');
        Schema::dropIfExists('rooms');
    }
};
