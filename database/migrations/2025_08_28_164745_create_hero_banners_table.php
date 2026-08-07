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
        Schema::create('hero_banners', function (Blueprint $table) {
            $table->id();
            $table->enum('locale', ['en', 'dv'])->index();
            $table->string('title', 120);
            $table->string('subtitle', 200)->nullable();
            $table->string('primary_cta_text', 60)->nullable();
            $table->string('primary_cta_url')->nullable();
            $table->string('secondary_cta_text', 60)->nullable();
            $table->string('secondary_cta_url')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedTinyInteger('overlay_opacity')->default(40);
            $table->integer('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('start_at')->nullable()->index();
            $table->timestamp('end_at')->nullable()->index();
            $table->timestamps();
            
            // Composite indexes for efficient queries
            $table->index(['locale', 'is_active']);
            $table->index(['locale', 'is_active', 'sort_order']);
            $table->index(['locale', 'is_active', 'start_at', 'end_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_banners');
    }
};
