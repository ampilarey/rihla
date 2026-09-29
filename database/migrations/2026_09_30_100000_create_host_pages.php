<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A host's own page on Rihla — §16.5, §16.8, §16 Phase 14.4.
 *
 * One per host. The host chooses a layout, two colours (each checked for
 * contrast before it is saved), a font from a short list the site already
 * loads, a logo and cover, a tagline and story in each language, the
 * sections to show and a few questions and answers. Nothing is shown
 * until it is published, and a host that is not active and verified has
 * no page at all, published or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('layout', 10)->default('story');
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('colour_primary', 7)->nullable();
            $table->string('colour_accent', 7)->nullable();
            $table->string('font', 20)->default('inter');
            $table->json('tagline')->nullable();
            $table->json('about')->nullable();
            $table->json('sections')->nullable();
            $table->json('faq')->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('instagram')->nullable();
            $table->string('facebook')->nullable();
            $table->string('website_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_pages');
    }
};
