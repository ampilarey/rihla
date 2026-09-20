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
                // Comment lines are skipped, and that is not laziness. This
                // guard's own fix in Ledger.php carried a comment naming the
                // pattern it had just removed, and the scan flagged the
                // prose — a guard that fires on an explanation of itself is
                // one people start ignoring, and then it catches nothing.
                if ($this->isComment($line)) {
                    continue;
                }

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

    /**
     * A model scope called on a bare `Builder` inside an arrow function.
     *
     * Larastan resolves scopes only when the builder's generic is known.
     * On a plain `Illuminate\Database\Eloquent\Builder` it reports "Call
     * to an undefined method Builder::unattended()" — and an arrow function
     * cannot carry a docblock, so there is nowhere to declare
     * `Builder<Incident>` and no way to silence it in place. The fix is
     * always the same: move the body into a named method with the generic
     * declared.
     *
     * This cost a CI run on the incidents table, which is the whole reason
     * these guards exist.
     */
    public function test_no_model_scope_called_on_a_bare_builder_in_an_arrow_function(): void
    {
        $scopes = $this->modelScopeNames();
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            foreach (file($file->getPathname()) as $number => $line) {
                if ($this->isComment($line)) {
                    continue;
                }

                // fn (Builder $q): Builder => $q->someScope(...)
                if (! preg_match('/fn\s*\(\s*Builder\s+\$(\w+)\s*\)[^=]*=>\s*\$(\w+)->(\w+)\(/', $line, $m)) {
                    continue;
                }

                [, $parameter, $used, $method] = $m;

                if ($parameter === $used && in_array($method, $scopes, true)) {
                    $offenders[] = sprintf('%s:%d — %s', $this->relative($file->getPathname()), $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A model scope on a bare `Builder` inside an arrow function: Larastan cannot see it,',
                'and an arrow function has nowhere to declare `Builder<Model>`.',
                'Move it to a named method with the generic in a docblock.'],
            $offenders,
        )));
    }

    /**
     * Every `scopeFoo` on a model, as `foo`.
     *
     * Read from the source rather than listed, so a scope added tomorrow is
     * covered without anybody remembering this test exists.
     *
     * @return list<string>
     */
    private function modelScopeNames(): array
    {
        $names = [];

        foreach (File::files(app_path('Models')) as $file) {
            preg_match_all('/function\s+scope([A-Z]\w*)\s*\(/', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $name) {
                $names[] = lcfirst($name);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * A line that is nothing but a comment.
     *
     * Deliberately crude: a line starting `//`, `#`, `/*` or a docblock
     * `*`. It does not try to track whether a multi-line comment is open,
     * because the only thing that would buy is catching an offender that is
     * already commented out — which is not a defect — while every extra
     * rule is another way for the scan to go wrong quietly.
     */
    private function isComment(string $line): bool
    {
        return (bool) preg_match('#^\s*(//|\#|/\*|\*)#', $line);
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
