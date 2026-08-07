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
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->date('date_start');
            $table->date('date_end');
            $table->string('location')->nullable();
            $table->text('summary')->nullable();
            $table->longText('details')->nullable();
            $table->unsignedInteger('price_from_mvr')->nullable();
            $table->enum('status', ['current', 'upcoming', 'past'])->default('upcoming');
            $table->string('cover_image')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
