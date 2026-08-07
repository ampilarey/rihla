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
        Schema::table('hero_banners', function (Blueprint $table) {
            // Heading styling
            $table->string('heading_color', 20)->default('#ffffff')->after('overlay_opacity');
            $table->string('heading_size', 20)->default('text-2xl')->after('heading_color');
            $table->string('heading_weight', 20)->default('font-bold')->after('heading_size');
            
            // Subheading styling
            $table->string('subheading_color', 20)->default('#f3f4f6')->after('heading_weight');
            $table->string('subheading_size', 20)->default('text-lg')->after('subheading_color');
            $table->string('subheading_weight', 20)->default('font-normal')->after('subheading_size');
            
            // Primary CTA styling
            $table->string('primary_cta_bg_color', 20)->default('#0ea5e9')->after('subheading_weight');
            $table->string('primary_cta_text_color', 20)->default('#ffffff')->after('primary_cta_bg_color');
            $table->string('primary_cta_size', 20)->default('text-base')->after('primary_cta_text_color');
            $table->string('primary_cta_radius', 20)->default('rounded-lg')->after('primary_cta_size');
            
            // Secondary CTA styling
            $table->string('secondary_cta_bg_color', 20)->default('rgba(255,255,255,0.2)')->after('primary_cta_radius');
            $table->string('secondary_cta_text_color', 20)->default('#ffffff')->after('secondary_cta_bg_color');
            $table->string('secondary_cta_size', 20)->default('text-base')->after('secondary_cta_text_color');
            $table->string('secondary_cta_radius', 20)->default('rounded-lg')->after('secondary_cta_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hero_banners', function (Blueprint $table) {
            $table->dropColumn([
                'heading_color', 'heading_size', 'heading_weight',
                'subheading_color', 'subheading_size', 'subheading_weight',
                'primary_cta_bg_color', 'primary_cta_text_color', 'primary_cta_size', 'primary_cta_radius',
                'secondary_cta_bg_color', 'secondary_cta_text_color', 'secondary_cta_size', 'secondary_cta_radius'
            ]);
        });
    }
};
