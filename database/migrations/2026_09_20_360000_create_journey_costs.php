<?php

use App\Support\JourneyProfit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a departure costs to run — §8.4's per-journey profitability.
 *
 * "Per-journey profitability is the report that changes pricing
 * decisions." It cannot exist without the other half of the arithmetic:
 * revenue is already in this system, and nothing here has ever recorded
 * what was paid out.
 *
 * ## Per person or per departure is the distinction that decides the answer
 *
 * A coach costs the same whether eighteen people or thirty are on it; a
 * hotel bed does not. Without `is_per_person` the margin is wrong at every
 * party size except the one somebody happened to have in mind, and wrong
 * in a direction that flatters a small group.
 *
 * ## Estimated, committed, paid — and the report says which it used
 *
 * An estimate is a plan, a committed cost is a contract somebody signed,
 * and a paid one is money that has left. A profit figure built on
 * estimates is a forecast; one built on paid costs is history. Both are
 * worth having and they are not the same number, so the status is stored
 * and {@see JourneyProfit} says which it counted.
 *
 * ## The currency is stored on every row
 *
 * Hotels bill in SAR, airlines in USD, the office collects MVR. [R-7]:
 * integer minor units, and nothing is ever added across currencies without
 * a rate somebody has actually stated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departure_costs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();

            // 'hotel', 'flight', 'transport', 'visa', 'permit', 'staff',
            // 'food', 'other'. What the money went on, in the words the
            // office uses when it asks where the money went.
            $table->string('category', 20);

            // Who was paid. Free text: the suppliers are a handful of
            // hotels and one airline, and a supplier table nobody
            // maintains is worse than a name somebody typed.
            $table->string('supplier')->nullable();

            $table->string('description')->nullable();

            // [R-7] again: minor units, with the currency beside them.
            $table->string('currency', 3)->default('MVR');
            $table->unsignedBigInteger('amount_minor');

            // The distinction that decides the answer. A coach is fixed; a
            // hotel bed is not.
            $table->boolean('is_per_person')->default(false);

            // 'estimated', 'committed', 'paid'.
            $table->string('status', 20)->default('estimated');

            $table->date('incurred_on')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['departure_id', 'status']);
            $table->index(['departure_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departure_costs');
    }
};
