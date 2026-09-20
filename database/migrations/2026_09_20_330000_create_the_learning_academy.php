<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Umrah Learning Academy — §7.3.
 *
 * ## A module is a knowledge article that is due on a date
 *
 * It carries the same editorial gate as §7.1 and §7.2 — sources, a named
 * scholar, approval before publication — because a module teaching somebody
 * how to perform tawaf is a religious claim in the same way a Knowledge
 * Centre article is, and the person reading it is about to act on it.
 *
 * What a module adds is `days_before_departure`. §7.3 asks for "a
 * personalised study plan keyed to the departure date (60 / 30 / 7 days
 * out)", and that is the whole of what personalises it: everything else
 * about a plan is arithmetic on a date this system already holds. Storing
 * the offset on the module rather than building three fixed buckets means
 * the office can move a module without a migration, and means the plan
 * stays right for a departure that is brought forward.
 *
 * ## Progress belongs to the person, not the booking
 *
 * `module_completions` keys on `traveller_id`. Somebody who travels twice
 * keeps what they learned the first time; keying on the booking would make
 * them start again, which is both wrong and insulting.
 *
 * ## A quiz question is a claim, so it carries sources
 *
 * Through the same polymorphic `article_references` table. A question that
 * tells a pilgrim they were *wrong* about a rite needs its source more than
 * an article does, not less — the article informs, the quiz corrects.
 *
 * ## The itinerary tie-in
 *
 * §7.3: "if the trip visits Uhud, surface Uhud's history the week before."
 * A module can point at a Ziyarah location (§7.2), and the study plan
 * raises it when that departure's itinerary mentions the place. Nullable,
 * because most modules are about rites rather than places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_paths', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();

            $table->json('name');
            $table->json('summary')->nullable();

            // 'beginner', 'intermediate', 'advanced', 'women', 'family',
            // 'children' — §7.3's list. Who the path is written for, which
            // is the only thing that distinguishes one path from another.
            $table->string('audience', 20);

            $table->boolean('is_published')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });

        Schema::create('learning_modules', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();

            $table->json('title');
            $table->json('summary')->nullable();
            $table->json('body')->nullable();

            // Minutes. Shown on the plan, because "eleven modules" means
            // nothing to somebody deciding whether to start one now and
            // "about 6 minutes" means everything.
            $table->unsignedSmallInteger('minutes')->nullable();

            // When this becomes due, counted back from the departure date.
            // 60, 30 and 7 are §7.3's suggestion rather than a constraint.
            $table->unsignedSmallInteger('days_before_departure')->default(30);

            // §7.3's itinerary tie-in. Null for a module about a rite.
            $table->foreignId('ziyarah_location_id')->nullable()
                ->constrained('ziyarah_locations')->nullOnDelete();

            // The same state machine, and the same two-person rule.
            $table->string('status', 20)->default('draft');

            $table->foreignId('written_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->text('withdrawn_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'days_before_departure']);
        });

        Schema::create('learning_path_module', function (Blueprint $table) {
            $table->id();

            $table->foreignId('learning_path_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_module_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);

            // A module can belong to several paths — "common mistakes"
            // belongs in every one of them — but only once in each.
            $table->unique(['learning_path_id', 'learning_module_id'], 'path_module_unique');
            $table->index(['learning_path_id', 'sort_order']);
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('learning_module_id')->constrained()->cascadeOnDelete();

            $table->json('prompt');

            // Shown after answering, right or wrong. The explanation is the
            // part that teaches; the mark is only bookkeeping.
            $table->json('explanation')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['learning_module_id', 'sort_order']);
        });

        Schema::create('quiz_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quiz_question_id')->constrained()->cascadeOnDelete();

            $table->json('text');

            $table->boolean('is_correct')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['quiz_question_id', 'sort_order']);
        });

        Schema::create('module_completions', function (Blueprint $table) {
            $table->id();

            // The person, not the booking. Somebody who travels twice keeps
            // what they learned.
            $table->foreignId('traveller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_module_id')->constrained()->cascadeOnDelete();

            $table->timestamp('read_at')->nullable();

            // The last attempt, not the best. A pilgrim who got four of
            // five and went back to read the fifth should see four of five
            // replaced, not a high score preserved — the number is a
            // prompt to go back, not an achievement.
            $table->unsignedSmallInteger('questions_answered')->nullable();
            $table->unsignedSmallInteger('questions_correct')->nullable();
            $table->timestamp('quiz_taken_at')->nullable();

            $table->timestamps();

            $table->unique(['traveller_id', 'learning_module_id'], 'completion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_completions');
        Schema::dropIfExists('quiz_options');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('learning_path_module');
        Schema::dropIfExists('learning_modules');
        Schema::dropIfExists('learning_paths');
    }
};
