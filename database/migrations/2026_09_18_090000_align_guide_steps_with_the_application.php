<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The guide_steps table and the code that uses it never agreed.
 *
 * The table has one body-text column, `description`, and an image column
 * `photo_path`. The model, the admin controller, the public guide, the PDF
 * export and the JSON API all expect `summary`, `details`, `video_url` and
 * `image_path` instead. None of those existed.
 *
 * The consequences were live: creating or editing a step in the admin panel
 * threw "no column named summary" and returned a 500, so the Umrah guide
 * could not be edited at all; and every published step rendered its title
 * followed by nothing, because the fields the view reads were always null.
 *
 * `description` is kept as the source of `summary` rather than renamed, so
 * existing guide content survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guide_steps', function (Blueprint $table) {
            // A short teaser shown on the step card; the admin form requires it.
            $table->text('summary')->nullable()->after('title');
            // The expandable body of the step.
            $table->longText('details')->nullable()->after('summary');
            $table->string('video_url')->nullable()->after('fiqh_notes');
            $table->string('image_path')->nullable()->after('video_url');
        });

        // Carry the existing content across. The single `description` field
        // maps to `summary`, which is where the guide renders one paragraph.
        DB::table('guide_steps')->update(['summary' => DB::raw('description')]);
        DB::table('guide_steps')->update(['image_path' => DB::raw('photo_path')]);

        Schema::table('guide_steps', function (Blueprint $table) {
            $table->dropColumn(['description', 'photo_path']);
        });
    }

    public function down(): void
    {
        Schema::table('guide_steps', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->string('photo_path')->nullable();
        });

        DB::table('guide_steps')->update(['description' => DB::raw('summary')]);
        DB::table('guide_steps')->update(['photo_path' => DB::raw('image_path')]);

        // Note: `details` has no pre-existing column to return to, so rolling
        // back discards it. There is nowhere for it to go.
        Schema::table('guide_steps', function (Blueprint $table) {
            $table->dropColumn(['summary', 'details', 'video_url', 'image_path']);
        });
    }
};
