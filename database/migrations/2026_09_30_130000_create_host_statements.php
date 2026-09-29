<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly statements for hosts — §16.5, §16.9, §16 Phase 15.
 *
 * A statement is the month's figures **frozen on the day it was issued**:
 * a stay corrected afterwards changes next month's view, not a document a
 * host has already filed. One per host, month and currency. The PDF is
 * rendered from the row whenever it is asked for, so there is no file to
 * keep in step with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 3);
            $table->unsignedInteger('marketplace_count')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->unsignedInteger('direct_count')->default(0);
            $table->bigInteger('direct_gross_minor')->default(0);
            $table->bigInteger('paid_to_rihla_minor')->default(0);
            $table->bigInteger('paid_here_minor')->default(0);
            $table->bigInteger('rihla_holds_minor')->default(0);
            $table->bigInteger('commission_outstanding_minor')->default(0);
            $table->string('reference', 30)->unique();
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['partner_id', 'period_start', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_statements');
    }
};
