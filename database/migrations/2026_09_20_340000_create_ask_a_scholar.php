<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ask a Scholar" — §6.4.
 *
 * ## A question is a private message until the asker says otherwise
 *
 * People ask about things they are worried about: a rite they think they
 * got wrong, a marriage, an illness, money. §6.2 settled that privacy in
 * this system belongs to the pilgrim, and the same rule applies here with
 * more force — so `may_publish` is asked for at the moment of asking,
 * defaults to false, and nothing reaches a public page without it.
 *
 * The question text is not translatable. It is somebody's own words, and a
 * translation column invites a machine to paraphrase what a pilgrim said
 * about their own circumstances.
 *
 * ## Declining is a first-class outcome
 *
 * "We are not the right people to answer this" is an honest answer and
 * sometimes the only correct one. Leaving a question unanswered is not, so
 * the state machine has a way out that requires a reason, and the queue
 * counts only what nobody has dealt with.
 *
 * ## Nothing answers automatically
 *
 * There is no template, no canned reply and no assistant here. §9.6's
 * pilgrim assistant is Phase 6 and is an assistant, not a mufti. An answer
 * on this table was typed by a person and carries that person's name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholar_questions', function (Blueprint $table) {
            $table->id();

            // Who asked. The booking is how the portal knows them, and the
            // traveller is the person — both, because a booking can be
            // cancelled and the question still deserves an answer.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('traveller_id')->nullable()->constrained()->nullOnDelete();

            // Somebody's own words. Deliberately not translatable: see the
            // class comment.
            $table->text('body');

            // What they were reading in when they asked, so the answer can
            // come back in the same language.
            $table->string('locale', 5)->default('en');

            // 'asked', 'answered', 'declined'.
            $table->string('status', 20)->default('asked');

            // Consent, given at the moment of asking and never assumed.
            $table->boolean('may_publish')->default(false);

            $table->foreignId('answered_by')->nullable()->constrained('people')->nullOnDelete();
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();

            $table->text('declined_reason')->nullable();

            // Only ever set when `may_publish` is true. Enforced on the
            // model, because a nullable column enforces nothing.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholar_questions');
    }
};
