<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Read a filled-in translation spreadsheet back into the language files.
 *
 * The half that makes `rihla:translations:export` worth anything: without it
 * a translator's work sits in a CSV nobody can apply.
 *
 * It refuses fabrication on the way in, using the rule that caught the
 * original: unrelated English strings must not collapse to one identical
 * Dhivehi string. `en/messages.php` deliberately dual-keys some strings
 * ('Manage Trips' and 'manage_trips'), so the check compares the English
 * meaning behind each key rather than the keys themselves — a blunter rule
 * flagged those too and would have deleted eight real translations.
 *
 * Nothing is written until every row passes. A partial import would leave the
 * files in a state nobody chose.
 */
class ImportTranslations extends Command
{
    protected $signature = 'rihla:translations:import
                            {file : The filled-in CSV from rihla:translations:export}
                            {--locale=dv : The locale being written}
                            {--dry-run : Report what would change and write nothing}';

    protected $description = 'Apply a filled-in translation spreadsheet';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! File::exists($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $locale = (string) $this->option('locale');
        $fallback = (string) config('app.fallback_locale');

        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        // Skip the byte-order mark the export writes for Excel.
        $first = fgets($handle, 4);

        if ($first !== false && ! str_starts_with($first, "\xEF\xBB\xBF")) {
            rewind($handle);
        } else {
            fseek($handle, 3);
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error('The file is empty.');
            fclose($handle);

            return self::FAILURE;
        }

        // A spreadsheet that has been opened and saved again can leave a
        // byte-order mark inside the first header cell as well as at the start
        // of the file, which is exactly what the machine most likely to edit
        // this will do. Strip it from every name rather than only the file.
        $columns = array_flip(array_map(
            static fn ($name) => trim(trim((string) $name), "\u{FEFF}"),
            $header,
        ));

        foreach (['file', 'key', 'english', 'new_'.$locale] as $required) {
            if (! isset($columns[$required])) {
                $this->error("The CSV has no [{$required}] column. Use the file rihla:translations:export wrote.");
                fclose($handle);

                return self::FAILURE;
            }
        }

        /** @var array<string, array<string, string>> $updates */
        $updates = [];
        $rows = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rows++;
            $new = trim((string) ($row[$columns['new_'.$locale]] ?? ''));

            if ($new === '') {
                continue;
            }

            $group = trim((string) $row[$columns['file']]);
            $key = (string) $row[$columns['key']];

            $updates[$group][$key] = $new;
        }

        fclose($handle);

        if ($updates === []) {
            $this->warn("Read {$rows} rows and found nothing filled in. Nothing to do.");

            return self::SUCCESS;
        }

        $problems = $this->fabricationProblems($updates, $locale, $fallback);

        if ($problems !== []) {
            $this->error('Refusing to import. Unrelated strings would render identically:');

            foreach ($problems as $problem) {
                $this->line('  '.$problem);
            }

            $this->newLine();
            $this->warn('That is the signature of machine-generated filler, which is how the');
            $this->warn('Dhivehi on this site went wrong the first time. Nothing has been written.');

            return self::FAILURE;
        }

        foreach ($updates as $group => $strings) {
            $target = resource_path("lang/{$locale}/{$group}.php");
            $existing = File::exists($target) ? require $target : [];
            $merged = array_merge(is_array($existing) ? $existing : [], $strings);

            $this->line(sprintf('  %-10s %d changed, %d total', $group.'.php', count($strings), count($merged)));

            if (! $this->option('dry-run')) {
                File::ensureDirectoryExists(dirname($target));
                File::put($target, $this->render($merged));
            }
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Imported. Run `php artisan test --filter=TranslationQualityTest` to confirm.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, string>>  $updates
     * @return list<string>
     */
    private function fabricationProblems(array $updates, string $locale, string $fallback): array
    {
        $problems = [];

        foreach ($updates as $group => $strings) {
            $englishPath = resource_path("lang/{$fallback}/{$group}.php");
            $english = File::exists($englishPath) ? require $englishPath : [];

            $translatedPath = resource_path("lang/{$locale}/{$group}.php");
            $existing = File::exists($translatedPath) ? require $translatedPath : [];

            $merged = array_merge(is_array($existing) ? $existing : [], $strings);

            $byValue = [];

            foreach ($merged as $key => $value) {
                if (is_string($value)) {
                    $byValue[$value][] = $key;
                }
            }

            foreach ($byValue as $value => $keys) {
                if (count($keys) < 2) {
                    continue;
                }

                $meanings = array_unique(array_map(fn ($k) => $english[$k] ?? $k, $keys));

                if (count($meanings) > 1) {
                    $problems[] = sprintf('%s.php: %s would all read "%s"',
                        $group, implode(', ', array_slice($keys, 0, 4)), $value);
                }
            }
        }

        return $problems;
    }

    /** @param  array<string, string>  $strings */
    private function render(array $strings): string
    {
        ksort($strings);

        $lines = [
            '<?php',
            '',
            '// Written by `php artisan rihla:translations:import`.',
            '// Edit through the spreadsheet rather than by hand, so the fabrication',
            '// check runs: see docs/DHIVEHI_TRANSLATION.md.',
            '',
            'return [',
        ];

        foreach ($strings as $key => $value) {
            $lines[] = sprintf("    '%s' => '%s',",
                str_replace("'", "\\'", (string) $key),
                str_replace("'", "\\'", $value));
        }

        $lines[] = '];';

        return implode("\n", $lines)."\n";
    }
}
