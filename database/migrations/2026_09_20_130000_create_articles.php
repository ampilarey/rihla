<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Articles — the blog §4.4 asks for.
 *
 * Deliberately a plain resource rather than the visual page builder the
 * source specification proposes. That is a multi-month project, and what
 * Rihla needs is somewhere to answer the questions pilgrims actually ask
 * before they book: what to pack, how the visa works, what Ramadan in Makkah
 * is like. Those are articles, not landing pages.
 *
 * `published_at` rather than a boolean: an article is published *at a time*,
 * which is what a reader and a crawler both want to know, and it allows
 * writing one today to appear on Friday without anybody remembering to
 * press a button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            $table->json('title');
            $table->json('excerpt')->nullable();
            $table->json('body');

            $table->string('cover_image')->nullable();

            // Who wrote it. A Person, not a User: the people readers should
            // see are group leaders and scholars, not whoever holds a login.
            $table->foreignId('author_id')->nullable()->constrained('people')->nullOnDelete();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // The listing's only query: published, newest first.
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
