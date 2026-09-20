<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Ziyarah Guide — §7.2.
 *
 * ## The same editorial gate as the Knowledge Centre
 *
 * A location page asserts history, significance and etiquette. Those are
 * religious claims, so they carry the sources and the named scholar §7.1
 * requires — through the same polymorphic `article_references` table, not a
 * second copy of the rule.
 *
 * ## Common misconceptions are a first-class table
 *
 * §7.2 flags these specifically, and is right to: naming what pilgrims are
 * wrongly told is what prevents the innovations they are warned about. A
 * paragraph buried in the body would be skimmed past; a labelled pair of
 * "what people say" and "what is actually the case" is read.
 *
 * Each one carries its own sources, for the same reason: a correction with
 * no source is one more thing to take on trust.
 *
 * ## Coordinates, and deliberately no embedded map
 *
 * §11.4 wants Google Maps. Nobody has supplied an API key, and an unkeyed
 * Google map renders a grey rectangle stamped "for development purposes
 * only" across a page about the Prophet's mosque. So the coordinates are
 * stored and the page links out to a map the visitor's phone already has.
 * The embed arrives with the key, and nothing above it changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ziyarah_locations', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();

            $table->json('name');
            $table->json('summary')->nullable();

            // The long-form sections §7.2 lists. Separate columns rather
            // than one body, because a pilgrim standing outside a place
            // wants the etiquette and not the history, and a single blob
            // cannot be shown one section at a time.
            $table->json('history')->nullable();
            $table->json('significance')->nullable();
            $table->json('etiquette')->nullable();
            $table->json('best_time')->nullable();

            // 'makkah', 'madinah', 'other'. What a visitor filters by.
            $table->string('city', 20);

            // Coordinates for a link out, not for an embed. See the class
            // comment: an unkeyed map is worse than no map.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // The same state machine as a knowledge article, and the same
            // two-person rule behind it.
            $table->string('status', 20)->default('draft');

            $table->foreignId('written_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->text('withdrawn_reason')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['status', 'city']);
        });

        Schema::create('location_misconceptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ziyarah_location_id')->constrained()->cascadeOnDelete();

            // What pilgrims are commonly told, and what is actually the
            // case. Two fields rather than one paragraph, because the
            // contrast is the thing that lands.
            $table->json('belief');
            $table->json('correction');

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['ziyarah_location_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_misconceptions');
        Schema::dropIfExists('ziyarah_locations');
    }
};
