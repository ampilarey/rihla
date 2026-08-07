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
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->nullable()->constrained()->onDelete('cascade');
            $table->enum('type', ['photo', 'video']);
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->string('file_path')->nullable(); // For photos
            $table->string('video_url')->nullable(); // For videos (YouTube/TikTok)
            $table->string('thumb_path')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
