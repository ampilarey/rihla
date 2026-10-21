<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a booking taken at the desk needs that the checkout never did.
 *
 * `payment_schedules`: instalments — this much by this date. Information
 * for the customer (they print on the invoice) and a nudge for the office
 * (an overdue one shows on Today), not a rule the ledger enforces: money
 * is still recorded and checked exactly as before.
 *
 * `discounts`: the record behind a negative booking line — what kind, how
 * much, why, who approved it, and whether it was taken back. The line is
 * what the invoice prints and the total sums; this row is the reason an
 * auditor asks for.
 *
 * Both hang off `bookings`, so both are on PackageDepartureTest's
 * DEPENDENT_MIGRATIONS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->unsignedBigInteger('amount_minor');
            $table->date('due_on');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['booking_id', 'due_on']);
            $table->index('due_on');
        });

        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_line_id')->nullable()->constrained('booking_lines')->nullOnDelete();
            $table->string('kind', 8); // fixed percent
            $table->decimal('value', 12, 2);
            $table->bigInteger('amount_minor'); // what it came to, negative
            $table->string('reason', 255);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('payment_schedules');
    }
};
