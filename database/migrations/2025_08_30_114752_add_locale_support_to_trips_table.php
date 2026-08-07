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
        Schema::table('trips', function (Blueprint $table) {
            $table->string('locale', 2)->default('en')->after('id');
            $table->string('title_dv')->nullable()->after('title');
            $table->text('summary_dv')->nullable()->after('summary');
            $table->text('details_dv')->nullable()->after('details');
            $table->string('location_dv')->nullable()->after('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['locale', 'title_dv', 'summary_dv', 'details_dv', 'location_dv']);
        });
    }
};
