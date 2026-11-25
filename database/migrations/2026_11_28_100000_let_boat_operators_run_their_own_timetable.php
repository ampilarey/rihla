<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Other operators — docs/TRANSPORT_PLAN.md (T5).
 *
 * - `operator_memberships`: who works for which operator, as an owner (runs
 *   the timetable) or crew (boards passengers). Its own table rather than
 *   `host_memberships`, so a boat operator is never let into a
 *   guesthouse's panel, nor a host into an operator's.
 * - `boat_bookings.commission_pct`: Rihla's cut of a seat sold online,
 *   frozen when it is booked, as a stay freezes its commission. A seat the
 *   operator sells themselves carries none.
 * - `operator_statements`: one per operator, month and currency, issued by
 *   Rihla's staff and read by the operator.
 *
 * Every pointer is a plain indexed column, as elsewhere in Transport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('role', 20);
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('added_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['partner_id', 'user_id']);
        });

        Schema::table('boat_bookings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('commission_pct')->nullable();
        });

        Schema::create('operator_statements', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->unsignedBigInteger('partner_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->unsignedInteger('online_count')->default(0);
            $table->unsignedBigInteger('online_paid_minor')->default(0);
            $table->unsignedBigInteger('commission_minor')->default(0);
            $table->unsignedBigInteger('payable_minor')->default(0);
            $table->unsignedInteger('desk_count')->default(0);
            $table->unsignedBigInteger('desk_total_minor')->default(0);
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('issued_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['partner_id', 'period_start', 'currency'], 'operator_statements_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_statements');

        Schema::table('boat_bookings', function (Blueprint $table): void {
            $table->dropColumn('commission_pct');
        });

        Schema::dropIfExists('operator_memberships');
    }
};
