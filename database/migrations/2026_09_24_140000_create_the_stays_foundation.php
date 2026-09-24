<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partners, properties and room types — §15.4 (Phase 9.1).
 *
 * The first three tables of the Stays engine: who owns the guesthouse, what
 * is being sold, and what a night in it actually buys. Availability (rates,
 * blocked dates) and the stays themselves come next; this migration
 * deliberately stops before either, because the invariant that governs them
 * needs a concurrency test on MySQL and that is its own slice.
 *
 * **Rihla does not own these buildings.** That single fact shapes the
 * schema: `pricing_model` records whether the partner is paid a net rate or
 * a commission, `instant_book` defaults to false because availability is
 * not Rihla's to promise until a partner has given a written allotment
 * (§15.2 decision 1), and `green_tax_mode` exists because the Maldives green
 * tax is charged per guest per night and whether it is inside the quoted
 * rate is a per-guesthouse answer that a customer will otherwise discover at
 * check-out.
 *
 * Money is integer minor units throughout — [R-7], App\Support\Money. The
 * currency is a column rather than a constant because §15.2 decision 4 puts
 * guesthouse stays in USD and island holidays and Malé rooms in MVR: the
 * currency belongs to the product, not to the reader's language.
 *
 * Nothing here is reachable from the public site yet. The three Stays routes
 * still render the coming-soon page Phase 8.2 built, and the service
 * registry still has all three services off by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('island', 120)->nullable();

            // Who Rihla actually rings. §15.2 decision 8: there is no
            // partner portal and there is not going to be one in this plan —
            // eight guesthouses need a phone number, not a login.
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('email')->nullable();

            // How Rihla is paid. A net rate means the partner quotes what
            // they want and Rihla marks it up; a commission means the
            // partner's own rate is shown and Rihla takes a cut. The margin
            // reporting of §8.4 cannot be written without knowing which.
            $table->string('pricing_model', 20)->default('net_rate');
            $table->unsignedSmallInteger('commission_pct')->nullable();

            // Whether the green tax is inside the quoted rate or collected
            // at the property. Per partner, because it is a decision the
            // guesthouse makes, not Rihla.
            $table->string('green_tax_mode', 20)->default('at_property');

            $table->text('allotment_notes')->nullable();
            $table->text('contract_notes')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        Schema::create('properties', function (Blueprint $table): void {
            $table->id();

            // Restricted, not cascading: a partner with properties is not
            // deletable, which is the same rule bookings already impose on
            // customers. Losing a building because somebody tidied up a
            // contact record is not a recoverable mistake.
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();

            // `guesthouse` is Phase 9; `rental` is Phase 11's Malé rooms on
            // this same engine. The column exists now so that phase is a
            // content task rather than a migration against live bookings —
            // the same reasoning `packages.type` records one migration ago.
            $table->string('type', 20)->default('guesthouse');

            $table->string('slug')->unique();
            $table->string('island', 120)->nullable();

            // Translatable, per docs/adr/0001. English is the only language
            // the admin form requires; an empty Arabic falls back to English
            // and says so on the page rather than showing a blank or a
            // machine translation.
            $table->json('name');
            $table->json('summary')->nullable();
            $table->json('description')->nullable();
            $table->json('house_rules')->nullable();
            $table->json('check_in_instructions')->nullable();

            $table->json('amenities')->nullable();

            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();

            $table->string('cover_image')->nullable();

            // False by default, and the reason is §15.2 decision 1: until a
            // partner has given a written allotment, their availability is
            // not Rihla's to promise. Malé rooms flip this on; partner
            // guesthouses are asked first.
            $table->boolean('instant_book')->default(false);

            $table->unsignedSmallInteger('min_nights')->default(1);

            $table->string('currency', 3)->default('USD');

            // The booking policy, per property, with §15.2 decision 2 as the
            // defaults. Stored rather than computed because the page has to
            // print the policy the stay will actually be held to, and a
            // constant that moves later would reprint a policy the customer
            // never agreed to.
            $table->unsignedSmallInteger('deposit_pct')->default(30);
            $table->unsignedSmallInteger('balance_days_before')->default(14);
            $table->unsignedSmallInteger('free_cancel_days')->default(14);

            $table->boolean('is_published')->default(false);
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index(['type', 'is_published']);
            $table->index(['island', 'is_published']);
        });

        Schema::create('room_types', function (Blueprint $table): void {
            $table->id();

            // Cascading here, unlike the partner above: a room type has no
            // meaning apart from its property, and deleting the building
            // should not leave its rooms behind. The stays that reference a
            // room type are what stop the delete, and they arrive in 9.3.
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            $table->json('name');
            $table->json('description')->nullable();

            $table->unsignedSmallInteger('sleeps')->default(2);
            $table->string('beds', 120)->nullable();
            $table->unsignedSmallInteger('size_m2')->nullable();
            $table->json('amenities')->nullable();

            // The ceiling the no-double-booking invariant counts against —
            // how many of this room the property physically has. Phase 9.2
            // enforces it inside a transaction with SELECT ... FOR UPDATE.
            $table->unsignedSmallInteger('quantity')->default(1);

            // The rate before any seasonal override. Minor units, in the
            // property's currency — a room type cannot have a currency of
            // its own, because a stay is one payment.
            $table->unsignedBigInteger('base_rate_minor')->default(0);

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index(['property_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        // Children first, because each holds a foreign key into the one
        // above it. Dropping a whole table takes its own indexes and
        // constraints with it, so the three-step dance AGENTS.md records is
        // not needed here — that trap is dropping a *column* that is still
        // constrained and indexed, which this migration does not do.
        Schema::dropIfExists('room_types');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('partners');
    }
};
