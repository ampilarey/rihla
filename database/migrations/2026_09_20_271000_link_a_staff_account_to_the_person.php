<?php

use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which staff account is which person — the gap §6.3 runs into.
 *
 * `departures.tour_leader_id` points at a {@see Person}, which is
 * the public-facing profile with a photo and a bio. The Tour Leader Portal
 * is opened by a {@see User}, which is a login. Nothing joined
 * the two, so the portal had no way to answer "which groups are mine".
 *
 * ## Why not simply show a leader every current departure
 *
 * Because a roster carries pilgrim names, ages and who is sharing a room
 * with whom. A leader who is not on that trip has no business reading it,
 * and "they probably won't look" is not an access rule.
 *
 * ## Nullable, unique, and null means nothing rather than everything
 *
 * Most staff accounts are not a public profile — finance, booking staff,
 * the content manager — so the column is nullable. It is unique because one
 * login is one person; two profiles claiming the same account would make
 * "my departures" ambiguous in a way nothing downstream could resolve.
 *
 * A leader whose account has not been linked sees **no** departures and is
 * told to ask the office, rather than falling back to seeing all of them.
 * The failure mode of a missing link has to be less access, never more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // nullOnDelete: closing a staff account unlinks the profile,
            // which stays — the person still led those trips, and deleting
            // the profile with the login would take their photo and bio off
            // every past departure.
            $table->foreignId('user_id')->nullable()->unique()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
