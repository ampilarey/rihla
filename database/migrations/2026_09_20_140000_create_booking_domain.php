<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The booking aggregate: who is paying, who is travelling, what was sold,
 * and the seats that were taken off the departure to sell it.
 *
 * Shape follows the plan's §5.1: `bookings` is the aggregate root over
 * `booking_travellers`, `booking_lines`, `seat_holds` and
 * `booking_status_transitions`, with a **package snapshot** so that editing
 * the package next month cannot change what somebody bought last month.
 * `customers` and `travellers` are separate entities because in this market
 * one person routinely books and pays for six — a mother, three children and
 * two grandparents — and only the payer will ever have a login.
 *
 * Money is integer minor units throughout ([R-7], App\Support\Money). The
 * line columns are **signed** big integers: a discount is a negative line,
 * and an unsigned column turns one into a silently enormous positive number.
 *
 * Nothing here is destructive. No existing table is altered except
 * `departures`, which gains a capacity constraint — see the companion
 * migration, which is separate so the invariant can be read and reviewed on
 * its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // A customer may have no login at all: booking staff take
            // bookings over the phone and by WhatsApp, and that is most of
            // them today. The account is created later, when the Pilgrim
            // Portal invitation is accepted, and linked here.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('national_id')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Not unique: two family members may share one email address,
            // and refusing the second booking because of it would be a
            // support call, not a data-quality win.
            $table->index('email');
            $table->index('phone');
        });

        Schema::create('travellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // As printed in the passport, in one field. Splitting it into
            // given/family names is wrong for a large share of Maldivian
            // names, which do not divide that way.
            $table->string('full_name');

            $table->date('date_of_birth')->nullable();

            // Recorded because the rules need it, not for demographics:
            // room allocation is single-sex, and a woman under 45 travelling
            // for Umrah has mahram requirements that operations must check.
            $table->string('gender', 10)->nullable();

            // How this traveller relates to the person paying — 'self',
            // 'spouse', 'child', 'parent', 'sibling', 'other'. Free-form
            // rather than an enum: the mahram rules are checked by a person
            // against the real relationship, and a short list would push
            // real cases into 'other' and hide them.
            $table->string('relationship', 30)->nullable();

            $table->string('nationality', 2)->default('MV');

            // The passport *number* lives here because the visa workflow
            // (§5.4a) needs it as a field, not as a file. The passport
            // *scan* is a document in the wallet (§5.5), versioned and
            // behind signed URLs — it is never stored on this row.
            $table->string('passport_number')->nullable();
            $table->string('passport_issuing_country', 2)->nullable();
            $table->date('passport_expiry')->nullable();

            $table->text('medical_notes')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('passport_expiry');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // RIH-B-2026-0417. Assigned after insert from the primary key —
            // see App\Models\Booking — so two booking staff pressing save in
            // the same second cannot mint the same one.
            $table->string('reference', 32)->nullable()->unique();

            // restrictOnDelete, deliberately, on both: a booking is a
            // financial record. Deleting the customer must fail loudly
            // rather than take the bookings with it, and deleting a
            // departure that has been sold must fail rather than orphan the
            // people flying on it.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('departure_id')->constrained()->restrictOnDelete();

            // What was sold, frozen. Package and departure rows go on being
            // edited — a hotel is swapped, a price is corrected, the
            // inclusions are reworded — and none of that may retroactively
            // change the contract. Read this, not the live relationship,
            // when showing somebody what they bought.
            $table->json('package_snapshot')->nullable();

            // One currency per booking, fixed at creation ([R-7]). Not
            // recomputed, not mixed: a booking quoted in MVR stays in MVR
            // even if a supplier invoice arrives in USD.
            $table->string('currency', 3)->default('MVR');

            $table->string('status', 20)->default('draft');

            // Seats this booking accounts for on the departure. Equal to the
            // number of travellers once they are all entered; the hold
            // exists before they are, which is why it is a column and not a
            // count.
            $table->unsignedSmallInteger('seats')->default(1);

            // Signed: see the class docblock.
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('deposit_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['departure_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('customer_id');
        });

        Schema::create('booking_travellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: a traveller with a booking is not deletable.
            $table->foreignId('traveller_id')->constrained()->restrictOnDelete();

            $table->string('occupancy', 20);
            $table->string('pax_type', 20)->default('adult');

            // Lineage, not the price. The tier may be repriced or removed;
            // amount_minor below is what this seat actually cost.
            $table->foreignId('price_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount_minor')->default(0);

            // The person operations calls. Exactly one per booking, enforced
            // by App\Models\Booking rather than by a partial unique index,
            // which MySQL does not have.
            $table->boolean('is_lead')->default(false);

            $table->string('room_reference')->nullable();
            $table->timestamps();

            // The same person cannot be on the same booking twice.
            $table->unique(['booking_id', 'traveller_id']);
        });

        Schema::create('booking_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_traveller_id')->nullable()->constrained()->nullOnDelete();

            // 'seat', 'extra', 'discount', 'fee'. See App\Models\BookingLine.
            $table->string('type', 20);
            $table->string('description');

            $table->unsignedSmallInteger('quantity')->default(1);
            $table->bigInteger('unit_amount_minor')->default(0);
            $table->bigInteger('amount_minor')->default(0);
            $table->string('currency', 3)->default('MVR');

            // [R-7]: if an amount was converted, the rate that was used is
            // stored on the line and never recomputed. A reconciliation six
            // months later must produce the same number as the receipt.
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->string('fx_from', 3)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['booking_id', 'sort_order']);
        });

        Schema::create('seat_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();

            // Nullable: booking staff hold seats for a family that is still
            // deciding, before any booking row exists.
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('seats');

            // A hold is live while released_at is null and expires_at is in
            // the future. Two nullable timestamps rather than a status
            // column, because "expired" is a fact about the clock and
            // storing it would mean a row that is wrong until a sweeper
            // corrects it.
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->string('released_reason', 40)->nullable();
            $table->timestamps();

            // The sweeper's query: live holds past their expiry.
            $table->index(['departure_id', 'released_at', 'expires_at']);
            $table->index(['released_at', 'expires_at']);
        });

        Schema::create('booking_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            // Null for a transition the system made — a hold expiring at
            // 02:00 has no author, and pretending otherwise would put a
            // name against something nobody did.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason')->nullable();

            // Append-only: created_at alone, like audit_logs. A status
            // history that can be edited is not a history.
            $table->timestamp('created_at')->nullable();

            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Children first: the foreign keys are real.
        Schema::dropIfExists('booking_status_transitions');
        Schema::dropIfExists('seat_holds');
        Schema::dropIfExists('booking_lines');
        Schema::dropIfExists('booking_travellers');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('travellers');
        Schema::dropIfExists('customers');
    }
};
