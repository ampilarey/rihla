<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Knowledge Centre — §7.1, and its editorial standard made structural.
 *
 * §7.1 is unusually specific about how this must be built: "every claim
 * carries a source (Qur'an reference or graded Hadith), every article has a
 * named scholar reviewer, and disputed/weak narrations are labelled as
 * such. **Build this into the CMS as required fields, not as a style guide
 * people forget.**"
 *
 * So the standard is the schema, not a wiki page. An article cannot reach
 * `approved` without a reference; a hadith reference cannot exist without a
 * grading; a weak or fabricated narration carries its grading onto the
 * public page. None of that is enforced by asking people nicely.
 *
 * ## A separate table from `articles`, deliberately
 *
 * `articles` is the blog: marketing writes it, nobody grades it, and it
 * publishes when the content manager says so. Folding religious content
 * into it would mean one `publish` button governing both, and the first
 * time somebody used the familiar one this whole apparatus would be
 * bypassed. Two tables is the cheap way to make that impossible.
 *
 * ## Nothing is seeded
 *
 * Not one article ships with this migration. AGENTS.md records that
 * fabricated Dhivehi and invented guide steps have already reached this
 * codebase, and religious text is the worst possible place to repeat that.
 * The machinery is the deliverable; the content is a scholar's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();

            // Translatable, like the rest of the content in this codebase
            // (ADR 0001). English is required; Dhivehi falls back rather
            // than being machine-filled.
            $table->json('title');
            $table->json('summary')->nullable();
            $table->json('body')->nullable();

            // 'place', 'event', 'person', 'dua', 'history'. A closed list so
            // the same subject is not filed four ways.
            $table->string('category', 20);

            // draft → in_review → approved → published, and withdrawn from
            // any of them. `approved` is separate from `published` because
            // a scholar signing something off and the office putting it on
            // the site are different acts by different people.
            $table->string('status', 20)->default('draft');

            $table->foreignId('written_by')->nullable()->constrained('users')->nullOnDelete();

            // The named scholar. A Person, not a User: §7.1 wants a name a
            // reader can see, and the scholar who reviews may never hold a
            // staff login.
            $table->foreignId('reviewed_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamp('published_at')->nullable();

            // Why it came down, when it does. A withdrawal with no reason
            // is the thing nobody can explain a year later.
            $table->text('withdrawn_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index('category');
        });

        Schema::create('article_references', function (Blueprint $table) {
            $table->id();

            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();

            // 'quran' or 'hadith'. Two kinds because they are cited and
            // graded differently, and conflating them is how an ungraded
            // hadith slips through wearing a verse's clothes.
            $table->string('kind', 10);

            // "Al-Baqarah 2:125", "Sahih al-Bukhari 1597". Free text: the
            // citation conventions differ by collection and a structured
            // schema would be wrong for the first one that does not fit.
            $table->string('citation');

            // 'sahih', 'hasan', 'daif', 'mawdu', 'disputed'.
            //
            // Nullable in the column and required in the model for hadith,
            // because a Qur'an reference has no grading and forcing one
            // would mean inventing a value. The rule lives where it can say
            // why.
            $table->string('grading', 20)->nullable();

            // "Included as a caution — pilgrims are often told this."
            $table->text('note')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['knowledge_article_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_references');
        Schema::dropIfExists('knowledge_articles');
    }
};
