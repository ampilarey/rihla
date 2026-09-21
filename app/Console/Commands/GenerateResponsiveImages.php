<?php

namespace App\Console\Commands;

use App\Support\ResponsiveImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Generate the missing `_768w` / `_1280w` / `_1920w` WebP files — §10.1.
 *
 * Hero banner uploads have made these since Phase 2. Trip, package and
 * article covers never have: those go through a plain file upload or a
 * Filament `FileUpload`, both of which store whatever was handed to them —
 * so a cover photograph straight off a telephone is served at its full
 * size to everybody. This backfills all of them, and is what to run after
 * adding a cover through a form that does not yet generate its own.
 *
 * Done with GD, which is on this host and on cPanel. No queue worker
 * exists (ADR 0002), so this is a command somebody runs rather than a job
 * something dispatches.
 *
 * **It never upscales.** A 900-pixel original produces a 768 and nothing
 * else: inventing a 1920-wide file from it makes a larger download that
 * looks worse, which is the opposite of the point.
 */
class GenerateResponsiveImages extends Command
{
    protected $signature = 'images:responsive
        {--dry-run : Report what would be generated and generate nothing}';

    protected $description = 'Generate responsive WebP variants for images that have none';

    /** @var array<string, string> table => the column holding the path */
    private const SOURCES = [
        'hero_banners' => 'image_path',
        'trips' => 'cover_image',
        'packages' => 'cover_image',
        'articles' => 'cover_image',
        'media' => 'file_path',
    ];

    public function handle(): int
    {
        if (! function_exists('imagewebp')) {
            $this->error('Refusing to run: this PHP has no GD WebP support, so nothing could be written.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $made = 0;
        $already = 0;
        $skipped = 0;

        foreach (self::SOURCES as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $path) {
                $path = (string) $path;

                if ($path === '' || ! Storage::disk('public')->exists($path)) {
                    $skipped++;

                    continue;
                }

                [$m, $a] = $this->generate($path, $dry);
                $made += $m;
                $already += $a;
            }
        }

        $this->newLine();
        $this->info(($dry ? 'Would generate ' : 'Generated ').$made.' file(s). '
            .$already.' already present, '.$skipped.' source image(s) missing from disk.');

        return self::SUCCESS;
    }

    /** @return array{int, int} how many were made, how many were already there */
    private function generate(string $path, bool $dry): array
    {
        $already = 0;
        $wanted = [];

        foreach (ResponsiveImage::WIDTHS as $width) {
            $variant = ResponsiveImage::variantPath($path, $width);

            if (Storage::disk('public')->exists($variant)) {
                $already++;

                continue;
            }

            $wanted[] = $variant;
        }

        if ($wanted === []) {
            return [0, $already];
        }

        foreach ($wanted as $variant) {
            $this->line('  '.$variant);
        }

        if ($dry) {
            // Counts what is missing rather than what would be written: a
            // dry run does not decode the image, so it cannot know which
            // widths the original is too small for. It over-reports for a
            // small image, which is the safe direction for a preview.
            return [count($wanted), $already];
        }

        // The encoding itself lives in ResponsiveImage, because the upload
        // observer needs the same thing and two copies of it would drift.
        return [ResponsiveImage::generate($path), $already];
    }
}
