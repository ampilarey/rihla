<?php

use App\Support\Brand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the palette change into the colours that live in the database.
 *
 * Hero banners store their own colours, so the wine/gold/ink/cream system
 * reached the stylesheets but not these rows: the column defaults were still
 * sky blue and a cool grey, and every banner created since kept them. A
 * banner made tomorrow would have arrived in the old brand.
 *
 * Only values that still equal the old defaults are rewritten. A colour an
 * editor chose deliberately is left alone, even if it happens to be blue —
 * this is a rebrand, not a veto.
 */
return new class extends Migration
{
    /** Old default => new default, per column. */
    private const REMAP = [
        'primary_cta_bg_color' => ['#0ea5e9', Brand::WINE],
        'subheading_color' => ['#f3f4f6', Brand::CREAM],
    ];

    public function up(): void
    {
        foreach (self::REMAP as $column => [$old, $new]) {
            DB::table('hero_banners')->where($column, $old)->update([$column => $new]);
        }

        Schema::table('hero_banners', function (Blueprint $table) {
            $table->string('primary_cta_bg_color', 20)->default(Brand::WINE)->change();
            $table->string('subheading_color', 20)->default(Brand::CREAM)->change();
        });
    }

    public function down(): void
    {
        foreach (self::REMAP as $column => [$old, $new]) {
            DB::table('hero_banners')->where($column, $new)->update([$column => $old]);
        }

        Schema::table('hero_banners', function (Blueprint $table) {
            $table->string('primary_cta_bg_color', 20)->default('#0ea5e9')->change();
            $table->string('subheading_color', 20)->default('#f3f4f6')->change();
        });
    }
};
