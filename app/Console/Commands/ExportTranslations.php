<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Write every Dhivehi string the site needs into one spreadsheet.
 *
 * Four audits found fabricated Dhivehi in this project — the Umrah guide
 * steps, both language files and the homepage — always with the same
 * signature: unrelated English strings collapsing to one identical Dhivehi
 * string. What was provably fabricated has been removed, so the Dhivehi site
 * now reads almost entirely in English. That is correct and it is not
 * finished.
 *
 * Finishing it needs a person who reads Thaana, and that person should not
 * have to open PHP files. This produces a CSV with one row per string:
 * what it says in English, what Dhivehi is there now, and an empty column to
 * write the real translation into. `rihla:translations:import` reads it back.
 *
 * Every existing Dhivehi value is included for checking, not only the missing
 * ones. The detector behind those audits catches fabrication — many keys
 * sharing one value — and cannot catch a translation that is merely wrong, so
 * nothing already in the file should be assumed sound.
 */
class ExportTranslations extends Command
{
    protected $signature = 'rihla:translations:export
                            {--path= : Where to write the CSV (default storage/app/translations)}
                            {--locale=dv : The locale being translated}';

    protected $description = 'Write a spreadsheet of every string needing translation';

    public function handle(): int
    {
        $locale = (string) $this->option('locale');
        $fallback = (string) config('app.fallback_locale');

        if ($locale === $fallback) {
            $this->error("[{$locale}] is the language everything is written in. Nothing to translate.");

            return self::FAILURE;
        }

        $directory = $this->option('path') ?: storage_path('app/translations');
        File::ensureDirectoryExists($directory);

        $target = $directory."/{$locale}-translations-".now()->format('Y-m-d').'.csv';

        $rows = [];

        foreach (File::files(resource_path("lang/{$fallback}")) as $file) {
            $group = $file->getFilenameWithoutExtension();
            $english = require $file->getPathname();

            $translatedPath = resource_path("lang/{$locale}/{$group}.php");
            $translated = File::exists($translatedPath) ? require $translatedPath : [];

            if (! is_array($english)) {
                continue;
            }

            foreach ($english as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $current = $translated[$key] ?? '';

                $rows[] = [
                    $group,
                    $key,
                    $value,
                    $current,
                    $current === '' ? 'MISSING — please translate' : 'please check, then correct or leave blank',
                    '',
                ];
            }
        }

        $handle = fopen($target, 'w');

        if ($handle === false) {
            $this->error("Cannot write to {$target}");

            return self::FAILURE;
        }

        // Excel reads a CSV as the local codepage unless the file opens with a
        // byte-order mark, and without one every Thaana character in this file
        // arrives as mojibake on the machine most likely to open it.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, ['file', 'key', 'english', 'current_'.$locale, 'status', 'new_'.$locale]);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $missing = count(array_filter($rows, fn ($r) => $r[3] === ''));

        $this->info("Wrote {$target}");
        $this->line('  '.count($rows).' strings in total');
        $this->line('  '.$missing.' with no Dhivehi at all');
        $this->line('  '.(count($rows) - $missing).' with a Dhivehi value that has never been checked by a speaker');
        $this->newLine();
        $this->line('Fill in the last column only where a change is needed, then:');
        $this->line('  php artisan rihla:translations:import '.$target);
        $this->newLine();
        $this->warn('The Umrah guide steps and the homepage "Why Choose Rihla" block are page');
        $this->warn('content, not interface strings — those are entered in Admin → Settings,');
        $this->warn('and the du\'as need a scholar rather than a translator.');

        return self::SUCCESS;
    }
}
