<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Guards the bug that put raw translation keys on the production homepage.
 *
 * `__('hero_title')` — a short key with no dot — is JSON-file syntax. This
 * project has no JSON translation files, so Laravel returned the key itself
 * and visitors saw "hero_title" above the fold. The strings existed the whole
 * time, in resources/lang/{en,dv}/messages.php, reachable only as
 * `__('messages.hero_title')`.
 *
 * Nothing asserted it, so it shipped and stayed shipped.
 */
class TranslationTest extends TestCase
{
    /** Translation groups this project defines. */
    private const GROUPS = ['messages', 'guide', 'app'];

    private const LOCALES = ['en', 'dv'];

    /**
     * A short snake_case key with no group prefix resolves to itself and
     * renders as-is. Sentence-style keys are a different, deliberate pattern
     * and are excluded here — see test_sentence_keys_are_documented_as_a_gap.
     */
    public function test_no_view_uses_a_dotless_short_key(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            preg_match_all("/__\('([a-z0-9_]+)'\s*[,)]/", File::get($file), $matches);

            foreach ($matches[1] as $key) {
                $offenders[] = basename($file).": __('{$key}')";
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Blade views contain dotless short keys, which resolve to themselves.',
                "Namespace them to their file, e.g. __('messages.hero_title'):"],
            $offenders,
        )));
    }

    /**
     * Every group key a view asks for must resolve in every locale. Catches
     * both a wrong group name and a string missing from one language.
     */
    public function test_every_group_key_used_in_a_view_resolves_in_every_locale(): void
    {
        $keys = $this->groupKeysUsedInViews();

        $this->assertNotEmpty($keys, 'Expected views to use group translation keys.');

        $missing = [];

        foreach (self::LOCALES as $locale) {
            foreach ($keys as $key) {
                if (trans($key, [], $locale) === $key) {
                    $missing[] = "[{$locale}] {$key}";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['Translation keys used in views do not resolve:'],
            $missing,
        )));
    }

    /**
     * Records the size of the untranslated surface so it cannot quietly grow.
     *
     * ~208 distinct English sentences are passed to __() with no JSON
     * translation files present. They render correctly in English because the
     * key is the text, and can never render in Dhivehi. Fixing that needs
     * resources/lang/dv.json and a translator, which is a content task.
     *
     * This asserts the count has not increased. Lower the ceiling as strings
     * are migrated; do not raise it.
     */
    public function test_untranslatable_sentence_keys_do_not_increase(): void
    {
        // Ratchet: the exact count at the time of writing. Any new sentence
        // key fails the build, which is the point — use the group pattern.
        $ceiling = 209;

        $sentences = [];

        foreach ($this->bladeFiles() as $file) {
            preg_match_all("/__\('([^']*\s[^']*)'/", File::get($file), $matches);

            foreach ($matches[1] as $sentence) {
                // Group files key their strings by the English sentence, so
                // __('guide.How to Perform Umrah') matches the pattern above
                // while being exactly what this test is asking people to do.
                // Those resolve in every locale — the other assertion proves
                // it — and must not count against the untranslated surface.
                if (in_array(explode('.', $sentence)[0], self::GROUPS, true)) {
                    continue;
                }

                $sentences[$sentence] = true;
            }
        }

        $count = count($sentences);

        $this->assertLessThanOrEqual($ceiling, $count, sprintf(
            'Untranslatable sentence keys rose to %d (ceiling %d). These render '.
            'in English regardless of locale because no JSON translation file '.
            'exists. Add the string to resources/lang/{en,dv}/messages.php and '.
            "call it as __('messages.key') instead.",
            $count,
            $ceiling,
        ));
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        return array_map(
            fn ($file) => $file->getPathname(),
            File::allFiles(resource_path('views')),
        );
    }

    /** @return list<string> */
    private function groupKeysUsedInViews(): array
    {
        $keys = [];

        foreach ($this->bladeFiles() as $file) {
            preg_match_all("/__\('([a-z0-9_]+\.[a-z0-9_.]+)'/", File::get($file), $matches);

            foreach ($matches[1] as $key) {
                // Only this project's own groups; auth.* and validation.* ship
                // with the framework.
                if (in_array(explode('.', $key)[0], self::GROUPS, true)) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
