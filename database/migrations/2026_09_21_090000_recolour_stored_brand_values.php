<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the violet palette into the colours that live in the database.
 *
 * `2026_09_18_170000_rebrand_stored_banner_colours` did this job for the
 * previous palette, and wrote its new values as `Brand::WINE` and
 * `Brand::CREAM` — constants, not literals. That migration has already run on
 * test and on production, stamping rows and a column default with whatever
 * those constants held at the time: `#8E2653` and `#FBF6EC`.
 *
 * Changing the constants moved the stylesheets and nothing else. Those rows
 * still say `#8E2653`, the live column default still says `#8E2653`, and a
 * banner created tomorrow would still be born in the old brand. Deployed
 * as-is, the homepage call-to-action renders maroon on a violet site.
 *
 * That is defect D11/D34 for the third time: colour held in the database, out
 * of reach of a class sweep. So every value here is a LITERAL on both sides.
 * A migration is a record of something that happened on a particular day; it
 * must not change meaning when a constant does.
 *
 * Only values equal to an old *brand* colour move. Those were chosen to match
 * the brand, so they follow it. Anything else an editor picked is left alone.
 */
return new class extends Migration
{
    /** Old brand hex => its counterpart in the violet palette. */
    private const REMAP = [
        '#8E2653' => '#5F498A',   // wine-500      -> violet-500
        '#731F43' => '#4C3A70',   // wine-600      -> violet-600
        '#5B1835' => '#3C2E59',   // wine-700      -> violet-700
        '#D2A03C' => '#EFD34D',   // gold-500      -> chiffon-500
        '#A87F2C' => '#A88C1F',   // gold-600      -> chiffon-600
        '#2E2621' => '#2E2245',   // ink           -> violet ink
        '#6B6159' => '#6B6080',   // ink-muted     -> violet ink-muted
        '#FBF6EC' => '#FFFDF0',   // cream         -> chiffon-50
        '#F4EDDF' => '#FEF9CD',   // cream-deep    -> chiffon-100
    ];

    /** table => columns that hold a colour. */
    private const COLUMNS = [
        'hero_banners' => [
            'heading_color', 'subheading_color',
            'primary_cta_bg_color', 'primary_cta_text_color',
            'secondary_cta_bg_color', 'secondary_cta_text_color',
        ],
        'why_sections' => [
            'title_color', 'subtitle_color',
            'primary_cta_bg_color', 'primary_cta_text_color',
            'secondary_cta_bg_color', 'secondary_cta_text_color',
        ],
        'why_features' => ['background_color'],
    ];

    public function up(): void
    {
        $this->rewrite(self::REMAP);

        if (Schema::hasTable('hero_banners')) {
            Schema::table('hero_banners', function (Blueprint $table) {
                $table->string('primary_cta_bg_color', 20)->default('#5F498A')->change();
                $table->string('subheading_color', 20)->default('#FFFDF0')->change();
            });
        }
    }

    public function down(): void
    {
        $this->rewrite(array_flip(self::REMAP));

        if (Schema::hasTable('hero_banners')) {
            Schema::table('hero_banners', function (Blueprint $table) {
                $table->string('primary_cta_bg_color', 20)->default('#8E2653')->change();
                $table->string('subheading_color', 20)->default('#FBF6EC')->change();
            });
        }
    }

    /**
     * @param  array<string, string>  $map
     */
    private function rewrite(array $map): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                foreach ($map as $from => $to) {
                    // Stored colours are hand-typed, so case varies.
                    DB::table($table)
                        ->whereRaw('UPPER('.$column.') = ?', [strtoupper($from)])
                        ->update([$column => $to]);
                }
            }
        }
    }
};
