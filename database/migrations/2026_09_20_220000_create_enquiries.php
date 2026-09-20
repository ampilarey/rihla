<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries — §8.1's minimal CRM.
 *
 * The plan is specific about what the first version is: "every enquiry
 * becomes a tracked lead with an owner and a next action — that alone beats
 * a shared inbox". So this table is built around those two columns and not
 * around a pipeline.
 *
 * ## No invented stages
 *
 * `status` holds what can be observed without asking anybody: it is new,
 * somebody is working on it, it became a booking, or it did not. A
 * qualification funnel with five stages is a description of how a sales team
 * works, and nobody has described this one. Adding stages later is a
 * migration; unpicking stages nobody uses is a habit.
 *
 * ## The next action is the point
 *
 * `next_action` and `next_action_at` are what turn a list into a queue.
 * An enquiry with neither is exactly the message that sits unanswered in a
 * shared inbox for four days, so the screen can find those and say so.
 *
 * ## It is not a customer until it is
 *
 * `customer_id` is filled in when an enquiry turns into somebody who books.
 * Creating a customer row for every enquiry would fill the table with people
 * who asked a price once, and the import work has just finished proving how
 * much that costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 40)->nullable()->unique();

            // 'web', 'whatsapp', 'phone', 'walk_in'. Where it came from,
            // because "how do people actually reach us" is a question the
            // operator cannot currently answer with anything but a guess.
            $table->string('source', 20);

            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('message')->nullable();

            // What they were looking at, when they were looking at anything.
            // nullOnDelete on both: an enquiry outlives a package that was
            // withdrawn, and losing the enquiry with it would lose the
            // person.
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('departure_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('party_size')->nullable();

            $table->string('status', 20)->default('new');

            // The two columns this table exists for.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('next_action')->nullable();
            $table->date('next_action_at')->nullable();

            // Where it went. A booking, or a reason it did not.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lost_reason')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'next_action_at']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('enquiry_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // 'note' for something somebody wrote, 'status' for a move the
            // system recorded. Both in one list, because the useful view is
            // "what has happened to this enquiry" in order.
            $table->string('type', 20)->default('note');
            $table->text('body');

            $table->timestamp('created_at')->nullable();

            $table->index(['enquiry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_notes');
        Schema::dropIfExists('enquiries');
    }
};
