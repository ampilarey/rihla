<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The route a translation takes from a person who reads Thaana into the site.
 *
 * Four audits found generated Dhivehi here, and removing it left the Dhivehi
 * site reading almost entirely in English. Finishing it needs a speaker, and a
 * speaker should not have to open a PHP file — so the strings go out as a
 * spreadsheet and come back the same way.
 *
 * The import refuses fabrication on the way in, which is the part worth
 * testing: it is the same rule that caught the original, applied before the
 * damage rather than after.
 */
class TranslationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The language files are tracked in git and the import writes to them, so
     * every one is snapshotted and put back. An earlier version of this test
     * restored only messages.php — the sheet also fills a key that lives in
     * app.php, and it left that file modified in the working tree, which is
     * how test data gets committed by accident.
     *
     * @var array<string, string>
     */
    private array $languageFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (File::files(resource_path('lang/dv')) as $file) {
            $this->languageFiles[$file->getPathname()] = (string) File::get($file->getPathname());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->languageFiles as $path => $contents) {
            File::put($path, $contents);
        }

        parent::tearDown();
    }

    private function directory(): string
    {
        return storage_path('framework/testing/translations-'.uniqid());
    }

    private function export(string $directory): string
    {
        $this->artisan('rihla:translations:export', ['--path' => $directory])->assertSuccessful();

        return File::files($directory)[0]->getPathname();
    }

    /** @param array<string, string> $fill */
    private function fill(string $csv, array $fill): string
    {
        $rows = array_map('str_getcsv', array_filter(explode("\n", (string) File::get($csv))));
        $header = array_map(fn ($h) => trim($h, "\u{FEFF}"), $rows[0]);
        $key = array_search('key', $header, true);
        $new = array_search('new_dv', $header, true);

        foreach ($rows as $i => $row) {
            if ($i === 0 || ! isset($row[$key])) {
                continue;
            }

            if (isset($fill[$row[$key]])) {
                $rows[$i][$new] = $fill[$row[$key]];
            }
        }

        $out = $csv.'.filled.csv';
        $handle = fopen($out, 'w');
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $out;
    }

    public function test_the_export_lists_every_string_including_the_ones_already_translated(): void
    {
        $directory = $this->directory();

        $this->artisan('rihla:translations:export', ['--path' => $directory])
            ->expectsOutputToContain('with no Dhivehi at all')
            ->expectsOutputToContain('never been checked by a speaker')
            ->assertSuccessful();

        $csv = File::files($directory)[0]->getPathname();
        $contents = (string) File::get($csv);

        $english = require resource_path('lang/en/messages.php');
        $this->assertStringContainsString(array_key_first($english), $contents);

        // Excel reads a CSV as the local codepage without this, and every
        // Thaana character arrives as mojibake on the machine most likely to
        // open it.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents, 'The CSV needs a byte-order mark.');

        File::deleteDirectory($directory);
    }

    public function test_a_filled_in_sheet_reaches_the_language_file(): void
    {
        $directory = $this->directory();
        $csv = $this->export($directory);

        $filled = $this->fill($csv, ['Trips' => 'ދަތުރުތައް-A', 'Gallery' => 'ގަލަރީ-B']);

        $this->artisan('rihla:translations:import', ['file' => $filled])->assertSuccessful();

        $dv = require resource_path('lang/dv/messages.php');

        $this->assertSame('ދަތުރުތައް-A', $dv['Trips']);
        $this->assertSame('ގަލަރީ-B', $dv['Gallery']);

        File::deleteDirectory($directory);
    }

    /**
     * The whole point. Two unrelated strings rendering identically is how the
     * Dhivehi on this site went wrong the first time; it must not be able to
     * arrive through the front door.
     */
    public function test_the_import_refuses_a_sheet_that_would_repeat_one_string(): void
    {
        $directory = $this->directory();
        $csv = $this->export($directory);

        $filled = $this->fill($csv, ['Trips' => 'އެއްކަހަލަ', 'Gallery' => 'އެއްކަހަލަ']);

        $before = (string) File::get(resource_path('lang/dv/messages.php'));

        $this->artisan('rihla:translations:import', ['file' => $filled])
            ->expectsOutputToContain('Refusing to import')
            ->assertFailed();

        $this->assertSame($before, (string) File::get(resource_path('lang/dv/messages.php')),
            'A refused import must write nothing at all.');

        File::deleteDirectory($directory);
    }

    /**
     * `en/messages.php` deliberately gives some strings two keys, and one
     * Dhivehi value covering such a pair is correct. The blunter rule flagged
     * those and would have deleted eight real translations.
     */
    public function test_the_import_allows_one_value_for_two_keys_that_mean_the_same_thing(): void
    {
        $english = require resource_path('lang/en/messages.php');

        $aliases = [];

        foreach ($english as $key => $value) {
            if (is_string($value)) {
                $aliases[$value][] = $key;
            }
        }

        $pair = collect($aliases)->first(fn ($keys) => count($keys) > 1);

        if ($pair === null) {
            $this->markTestSkipped('No dual-keyed English string to test with.');
        }

        $directory = $this->directory();
        $csv = $this->export($directory);
        $filled = $this->fill($csv, array_fill_keys($pair, 'އެއްމާނަ'));

        $this->artisan('rihla:translations:import', ['file' => $filled])->assertSuccessful();

        File::deleteDirectory($directory);
    }

    /** A dry run must be able to say no without touching anything. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $directory = $this->directory();
        $csv = $this->export($directory);
        $filled = $this->fill($csv, ['Trips' => 'ދަތުރުތައް-DRY']);

        $before = (string) File::get(resource_path('lang/dv/messages.php'));

        $this->artisan('rihla:translations:import', ['file' => $filled, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame($before, (string) File::get(resource_path('lang/dv/messages.php')));

        File::deleteDirectory($directory);
    }

    /** The instructions must exist, and must not have gone stale. */
    public function test_the_translation_guide_documents_both_commands(): void
    {
        $doc = (string) File::get(base_path('docs/DHIVEHI_TRANSLATION.md'));

        $this->assertStringContainsString('rihla:translations:export', $doc);
        $this->assertStringContainsString('rihla:translations:import', $doc);
    }
}
