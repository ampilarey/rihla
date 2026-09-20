<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The group leaders and scholars who actually travel with a party.
 *
 * "Pilgrims choose people, not packages" — §4.3. A first-time pilgrim
 * deciding between two operators is largely deciding whether they trust
 * whoever will be standing beside them at the miqat, and no operator in this
 * market publishes who that is.
 *
 * Separate from `users`: a scholar who travels with one group a year has no
 * business holding a login to the admin panel, and a member of staff who
 * administers the site is not necessarily anybody a pilgrim should read
 * about. Conflating the two would mean either handing out accounts nobody
 * needs or publishing rows that were never written for the public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            // Not translatable. A person's name is their name; transliterating
            // it into Thaana is a decision for whoever writes it, and doing it
            // automatically is how machine-generated Dhivehi got onto this
            // site before.
            $table->string('name');

            $table->string('role', 20);
            $table->json('title')->nullable();
            $table->json('bio')->nullable();
            $table->string('photo_path')->nullable();

            // Which languages they can actually speak to a pilgrim in. The
            // single most practical fact about a group leader for a
            // Maldivian party, and nowhere on any competitor's site.
            $table->json('languages')->nullable();

            // Counted, not estimated. Null means nobody has said, which the
            // page renders as nothing rather than as zero.
            $table->unsignedSmallInteger('groups_led')->nullable();

            $table->boolean('is_published')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
            $table->index('role');
        });

        Schema::table('departures', function (Blueprint $table) {
            // Two separate roles, and a departure may have one, both or
            // neither. nullOnDelete so removing a person never takes a
            // departure with them.
            $table->foreignId('tour_leader_id')->nullable()->after('airline')
                ->constrained('people')->nullOnDelete();
            $table->foreignId('scholar_id')->nullable()->after('tour_leader_id')
                ->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tour_leader_id');
            $table->dropConstrainedForeignId('scholar_id');
        });

        Schema::dropIfExists('people');
    }
};
