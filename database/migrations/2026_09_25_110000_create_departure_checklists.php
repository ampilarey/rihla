<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-departure checklists — §8.3.
 *
 * One row per thing somebody has to do before a departure leaves, with a
 * date it is due and who ticked it off. Nothing is seeded: the list is the
 * office's, and a checklist of plausible-sounding steps nobody wrote is
 * the same mistake as the invented social links. A departure's list can be
 * copied from another one instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departure_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->date('due_on')->nullable();
            $table->boolean('is_blocking')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['departure_id', 'done_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departure_checklist_items');
    }
};
