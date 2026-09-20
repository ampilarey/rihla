<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Local guards for mistakes that otherwise only CI catches.
 *
 * PHPStan cannot run here — it is dist-only in the lock file and cloning it
 * from source reached 5.7 GB — so static analysis is a CI-only check, and
 * every one of its findings costs a push, a run and several minutes. These
 * are the ones worth catching before that, because they are purely
 * syntactic and because they keep happening.
 */
class StaticAnalysisHabitsTest extends TestCase
{
    /**
     * `?->` on the left of `??` is redundant, and I have written it four
     * times in one day.
     *
     * `??` already suppresses a *property* access on null, so the nullsafe
     * operator adds nothing and PHPStan's nullsafe.neverNull rejects it.
     * Checked at runtime rather than taken on faith:
     *
     *     $null->prop     ?? 'x'  →  'x'
     *     $null->method() ?? 'x'  →  Error
     *
     * A method call is the opposite case and genuinely needs `?->`, which is
     * why this looks for a property access specifically — `?->name ??` — and
     * leaves `?->format() ??` alone.
     */
    public function test_no_nullsafe_property_access_on_the_left_of_a_coalesce(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            foreach (file($file->getPathname()) as $number => $line) {
                // A property, not a method: no "(" between the name and ??.
                if (preg_match('/\?->[A-Za-z_][A-Za-z0-9_]*\s*\?\?/', $line)) {
                    $offenders[] = sprintf('%s:%d — %s', $this->relative($file->getPathname()), $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['`?->` is redundant on the left of `??`: the coalesce already handles a null property access.',
                'Use `->`. (A method call is different and keeps its `?->`.)'],
            $offenders,
        )));
    }

    /** @return list<\SplFileInfo> */
    private function phpFiles(): array
    {
        $files = [];

        foreach (['app', 'database', 'routes', 'config'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
