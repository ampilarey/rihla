<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three currencies — batch C from Hijaz. Prices and balances stay in
 * rufiyaa; a payment may arrive in dollars or riyals, credited at the rate
 * Finance records that day; and the site may show what a price comes to
 * in the other two, from a rate table Finance keeps.
 *
 * `exchange_rates` is that table, append-only by convention: a new line
 * when the rate moves, so a payment recorded in March still shows March's
 * rate. On `payments`, the three columns say what was actually received
 * when it was not the booking's currency; `amount_minor` stays what the
 * booking is credited, so every sum in the ledger is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('currency', 3);
            $table->char('to_currency', 3);
            // Units of to_currency per one unit of currency: 15.420000.
            $table->decimal('rate', 14, 6);
            $table->date('effective_on');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['currency', 'to_currency', 'effective_on']);
            $table->index(['currency', 'to_currency', 'effective_on']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->char('received_currency', 3)->nullable()->after('amount_minor');
            $table->bigInteger('received_minor')->nullable()->after('received_currency');
            $table->decimal('exchange_rate', 14, 6)->nullable()->after('received_minor');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['received_currency', 'received_minor', 'exchange_rate']);
        });

        Schema::dropIfExists('exchange_rates');
    }
};
