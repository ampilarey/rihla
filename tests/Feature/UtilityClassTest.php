<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A Tailwind class built by interpolation compiles to nothing.
 *
 * Tailwind scans source files for literal class names. `grid-cols-{{ $n }}`
 * is not a literal class name, so no CSS is generated for it and the element
 * silently has no columns — which reads as a broken layout, not as a missing
 * style.
 *
 * This is D57 in the defect register: the header logo carried `w-50`, which
 * is not on Tailwind's spacing scale, so the class compiled to nothing and
 * the logo fell back to its intrinsic width. A class that emits no CSS is
 * worse than no class, because it reads like a constraint being honoured.
 *
 * `layouts/app.blade.php` carries a note warning about exactly this, and the
 * homepage's departure rail was written with `md:grid-cols-{{ … }}` anyway
 * before this test existed. A note is not a guard.
 */
class UtilityClassTest extends TestCase
{
    /**
     * Utility prefixes whose value decides what CSS is generated. A width, a
     * column count or a colour built at runtime produces no rule at all.
     *
     * @var list<string>
     */
    private const SCANNED_PREFIXES = [
        'grid-cols', 'col-span', 'w', 'h', 'max-w', 'min-w', 'gap',
        'p', 'px', 'py', 'pt', 'pb', 'ps', 'pe',
        'm', 'mx', 'my', 'mt', 'mb',
        'text', 'bg', 'border', 'rounded', 'flex', 'order', 'z',
    ];

    public function test_no_view_builds_a_tailwind_class_by_interpolation(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = (string) File::get($file);

            foreach ($this->classAttributes($contents) as $attribute) {
                foreach (self::SCANNED_PREFIXES as $prefix) {
                    // The prefix, a hyphen, then a Blade echo or a PHP tag.
                    if (preg_match('/(?:^|[\s:])'.preg_quote($prefix, '/').'-\{\{/', $attribute)) {
                        $offenders[] = basename($file).': '.trim($attribute);
                    }
                }
            }
        }

        $this->assertSame([], array_unique($offenders), implode("\n", array_merge(
            ['These class names are built by interpolation and compile to no CSS at all.'],
            ['Write each variant out in full, as layouts/app.blade.php does:'],
            array_unique($offenders),
        )));
    }

    /**
     * Class attributes, with Blade comments stripped first.
     *
     * The comment explaining this trap necessarily contains an example of
     * it, and a guard that fails on its own documentation teaches people to
     * delete the documentation.
     *
     * @return list<string>
     */
    private function classAttributes(string $contents): array
    {
        $withoutComments = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $contents);

        preg_match_all('/class="([^"]*)"/', $withoutComments, $matches);

        return $matches[1];
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        return array_map(
            fn ($file): string => $file->getPathname(),
            File::allFiles(resource_path('views')),
        );
    }
}
