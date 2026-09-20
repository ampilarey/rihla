<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money received against a booking — §5.3.
 *
 * ## Two tables, because a payment and an attempt are different things
 *
 * `payments` is one sum of money and what became of it. `payment_transactions`
 * is every event that happened to it: the slip arriving, a person reviewing
 * it, a gateway calling back. The plan's chain is
 * `Booking → Payment → PaymentTransaction → provider driver`, and the reason
 * is provider independence: BML appears in exactly one class, and everything
 * above it is the same whether the money came by card or by bank transfer.
 *
 * ## Callback idempotency rests on a unique constraint
 *
 * `(provider, provider_event_id)` is unique. A gateway that delivers the same
 * webhook twice — which they all do — gets a duplicate-key error on the
 * second, and the money is recorded once. Both columns are nullable so that
 * internal events carry no provider: MySQL and SQLite both allow repeated
 * NULLs in a unique index, which is exactly the behaviour wanted here.
 *
 * ## A refund is a negative payment, not a status
 *
 * `amount_minor` is signed, and a refund is a new row with a negative amount
 * pointing at the one it reverses. The original keeps its date, its reference
 * and its slip — the evidence of what was actually received — the same way a
 * refused visa application is kept rather than reopened. It also means the
 * paid total is a plain SUM with nothing to special-case.
 *
 * ## The slip lives on the payment, not in the document wallet
 *
 * `documents.traveller_id` is NOT NULL, and the person who pays is often not
 * one of the travellers — a father paying for three others, an employer, a
 * relative abroad. Rather than loosen the wallet's invariant so this fits,
 * the file is held here, on the same private disk and reachable only through
 * the same signed-URL-plus-policy-plus-audit path.
 *
 * ## [R-7] All money is integer minor units
 *
 * Laari for MVR, cents for USD. The currency is copied from the booking at
 * creation and never recomputed; if an exchange rate is ever applied it is
 * stored on the row that used it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete: a record of money received must not vanish
            // with the booking it was received for.
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();

            // What this reverses, for a refund. Self-referencing and
            // nullable: an ordinary payment reverses nothing.
            $table->foreignId('refund_of_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->string('reference', 40)->nullable()->unique();

            // 'bank_transfer', 'cash', 'card'. How the money arrived, which
            // is a different question from which driver handled it.
            $table->string('method', 20);

            // The driver that handled it, when one did. Null for money
            // entered by a member of staff.
            $table->string('provider', 30)->nullable();

            $table->string('currency', 3);

            // Signed: a refund is negative. bigInteger because laari add up
            // and an Umrah party of twelve is not a small number of them.
            $table->bigInteger('amount_minor');

            $table->string('status', 20)->default('pending');

            // When the money actually moved, as opposed to when the row was
            // made. For a bank transfer this is what the customer says, and
            // it is not evidence until somebody has looked at the slip.
            $table->timestamp('paid_at')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();

            // What the customer typed about the transfer. Free text on
            // purpose: a Maldivian bank reference is whatever the sender put
            // in the box, and normalising it would lose the thing that lets
            // somebody match it against a statement.
            $table->string('payer_name')->nullable();
            $table->string('payer_bank', 60)->nullable();
            $table->string('payer_reference', 80)->nullable();

            // The slip. Same private disk as the document wallet, same
            // random filename, same signed-URL-only access.
            $table->string('slip_disk', 30)->nullable();
            $table->string('slip_path')->nullable();
            $table->string('slip_original_filename')->nullable();
            $table->string('slip_mime_type', 100)->nullable();
            $table->unsignedBigInteger('slip_size_bytes')->nullable();
            $table->string('slip_checksum', 64)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 30)->nullable();

            // The gateway's own id for this event. The whole point of this
            // table: see the class docblock.
            $table->string('provider_event_id', 120)->nullable();

            $table->string('type', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            $table->bigInteger('amount_minor')->nullable();

            // Whatever the gateway sent, kept verbatim. When a reconciliation
            // is disputed a year later, the parsed fields are an
            // interpretation and this is the thing itself.
            $table->json('payload')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payments');
    }
};
