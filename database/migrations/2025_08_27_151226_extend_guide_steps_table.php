<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('guide_steps', function (Blueprint $table) {
            // Add new fields for enhanced Umrah guide
            $table->string('locale', 2)->default('en')->after('step_number'); // en|dv
            $table->text('dua_text')->nullable()->after('description'); // Optional dua text
            $table->text('reference_text')->nullable()->after('dua_text'); // Optional reference text
            $table->json('checklist')->nullable()->after('reference_text'); // JSON array of checklist items
            $table->json('fiqh_notes')->nullable()->after('checklist'); // JSON for different madhabs
            
            // Add indexes for performance
            $table->index(['locale', 'is_published', 'step_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guide_steps', function (Blueprint $table) {
            $table->dropIndex(['locale', 'is_published', 'step_number']);
            $table->dropColumn(['locale', 'dua_text', 'reference_text', 'checklist', 'fiqh_notes']);
        });
    }
};
