<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the CRM — §8.1.
 *
 * Phase 3 shipped the minimum that beats a shared inbox: every enquiry
 * becomes a tracked lead with an owner and a next action. What that leaves
 * out is everything between "somebody asked" and "somebody paid".
 *
 * ## Quotations: the artefact the office actually sends
 *
 * Today the price lives in a WhatsApp message and nowhere else, so nobody
 * can answer "what did we quote them?" a fortnight later. A quotation is
 * that answer: a priced offer with a date it stops being valid, recorded
 * against the enquiry it came from.
 *
 * Money is integer minor units throughout, never a float — the [R-7] rule
 * this codebase already follows everywhere else. The currency is stored
 * beside it because a number with no currency is a number somebody will
 * read in the wrong one.
 *
 * A quotation is **superseded, never edited**. Changing the price on a
 * quotation somebody has already been sent means the record no longer says
 * what they were told, which is the whole reason to keep one.
 *
 * ## Tasks: one next action was never enough
 *
 * `enquiries.next_action_at` holds one date. Real follow-up is "ring them
 * Tuesday, and chase the deposit on the 14th", and a single column forces
 * the second one to be forgotten or to overwrite the first.
 *
 * Deliberately polymorphic: a task hangs off an enquiry, a customer or a
 * booking, because "ring them about their passport" is not an enquiry and
 * pretending it is means inventing a fake lead to hold a reminder.
 *
 * ## Tags and referrals belong to the customer, not the lead
 *
 * A tag on an enquiry is lost the moment the enquiry closes. "Travels with
 * her mother", "prefers Ramadan" and "came from Ahmed" are facts about a
 * person that outlive every lead they will ever be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();

            $table->string('reference')->unique();

            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();

            // What was quoted for. Both nullable: an early quotation is
            // often "roughly this, for a trip like that" before anybody has
            // settled on a dated departure.
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('departure_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('party_size')->default(1);

            // [R-7]: integer minor units and a currency beside them, never
            // a float and never a bare number.
            $table->string('currency', 3)->default('MVR');
            $table->unsignedBigInteger('total_minor');

            // What the price covers, and what it does not. Free text
            // because the office writes it differently every time and a
            // structured version would be filled in wrongly.
            $table->text('includes')->nullable();
            $table->text('excludes')->nullable();

            // The date it stops being true. Required: a quotation with no
            // expiry is a price the operator is held to for ever.
            $table->date('valid_until');

            // 'draft', 'sent', 'accepted', 'declined', 'expired',
            // 'superseded'.
            $table->string('status', 20)->default('draft');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decline_reason')->nullable();

            // A quotation is replaced, never edited. This points at the one
            // that replaced it.
            $table->foreignId('superseded_by')->nullable()
                ->constrained('quotations')->nullOnDelete();

            // What it turned into, if it turned into anything.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['enquiry_id', 'created_at']);
            $table->index(['status', 'valid_until']);
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();

            // An enquiry, a customer or a booking. See the class comment:
            // "ring them about their passport" is not a lead.
            $table->nullableMorphs('about');

            $table->string('subject');
            $table->text('detail')->nullable();

            $table->date('due_on');

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The query the whole feature exists for: what is due, for whom.
            $table->index(['done_at', 'due_on']);
            $table->index(['owner_id', 'done_at', 'due_on']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // §8.1's referral tracking. Who sent them — a customer we
            // already have, not free text, because "Ahmed" is not a person
            // anybody can find later.
            $table->foreignId('referred_by_customer_id')->nullable()
                ->after('user_id')
                ->constrained('customers')->nullOnDelete();

            // How they found us when it was not a person: 'google',
            // 'facebook', 'walk_in'. Free-ish text kept short.
            $table->string('referral_source', 40)->nullable()->after('referred_by_customer_id');
        });

        Schema::create('customer_tags', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('tag', 40);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One tag once per customer. Without this the list fills with
            // "Ramadan" three times because three people added it.
            $table->unique(['customer_id', 'tag']);
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tags');

        Schema::table('customers', function (Blueprint $table) {
            // Foreign key, then index, then column — the order both engines
            // accept. MySQL refuses to drop the index while the constraint
            // stands; SQLite rebuilds the table and fails on anything still
            // naming the column.
            $table->dropForeign(['referred_by_customer_id']);
            $table->dropColumn(['referred_by_customer_id', 'referral_source']);
        });

        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('quotations');
    }
};
