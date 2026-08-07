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
        Schema::table('why_sections', function (Blueprint $table) {
            $table->string('title_color')->nullable()->after('secondary_cta_url');
            $table->string('subtitle_color')->nullable()->after('title_color');
            $table->string('primary_cta_bg_color')->nullable()->after('subtitle_color');
            $table->string('primary_cta_text_color')->nullable()->after('primary_cta_bg_color');
            $table->string('secondary_cta_bg_color')->nullable()->after('primary_cta_text_color');
            $table->string('secondary_cta_text_color')->nullable()->after('secondary_cta_bg_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('why_sections', function (Blueprint $table) {
            $table->dropColumn([
                'title_color',
                'subtitle_color', 
                'primary_cta_bg_color',
                'primary_cta_text_color',
                'secondary_cta_bg_color',
                'secondary_cta_text_color'
            ]);
        });
    }
};
