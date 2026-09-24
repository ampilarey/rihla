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
     * Records the size of the untranslated *public* surface so it cannot grow.
     *
     * These sentences are passed to __() with no JSON translation files
     * present. They render correctly in English because the key is the text,
     * and can never render in Dhivehi. On a public page that is a defect a
     * Maldivian visitor sees; fixing it needs resources/lang/dv.json and a
     * translator, which is a content task.
     *
     * Lower the ceiling as strings are migrated; do not raise it.
     */
    public function test_untranslatable_sentence_keys_on_public_pages_do_not_increase(): void
    {
        // Ratchet: the exact count at the time of writing.
        $ceiling = 98;

        $count = count($this->sentenceKeys(admin: false));

        $this->assertLessThanOrEqual($ceiling, $count, sprintf(
            'Untranslatable sentence keys on public pages rose to %d (ceiling %d). '.
            'These render in English regardless of locale because no JSON '.
            'translation file exists, so a Dhivehi visitor sees English. Add the '.
            'string to resources/lang/{en,dv}/messages.php and call it as '.
            "__('messages.key') instead.",
            $count,
            $ceiling,
        ));
    }

    /**
     * The same measure for the admin panel, tracked separately because it is a
     * different problem with a different answer.
     *
     * The admin panel is entirely English today and is used by Rihla staff,
     * not by customers. Whether it should be translated at all is an open
     * decision. Holding it to one shared ceiling with the public site meant a
     * new admin screen consumed budget that belongs to customer-facing text,
     * and hid how tight the public number actually is.
     */
    public function test_untranslatable_sentence_keys_in_the_admin_panel_do_not_increase(): void
    {
        // Lowered from 120 when the trip form stopped asking for the same
        // four fields twice over — once plainly and once labelled "(Dhivehi)".
        $ceiling = 116;

        $count = count($this->sentenceKeys(admin: true));

        $this->assertLessThanOrEqual($ceiling, $count, sprintf(
            'Untranslatable sentence keys in admin views rose to %d (ceiling %d).',
            $count,
            $ceiling,
        ));
    }

    /**
     * English sentences passed to __() with no group prefix, in one half of
     * the views or the other.
     *
     * @return list<string>
     */
    private function sentenceKeys(bool $admin): array
    {
        $sentences = [];

        foreach ($this->bladeFiles() as $file) {
            $isAdmin = str_contains(str_replace('\\', '/', $file), '/views/admin/');

            if ($isAdmin !== $admin) {
                continue;
            }

            preg_match_all("/__\\('([^']*\\s[^']*)'/", File::get($file), $matches);

            foreach ($matches[1] as $sentence) {
                // Group files key their strings by the English sentence, so
                // __('guide.How to Perform Umrah') matches the pattern above
                // while being exactly what this test asks people to do. Those
                // resolve in every locale — the assertion above proves it —
                // and must not count against the untranslated surface.
                if (in_array(explode('.', $sentence)[0], self::GROUPS, true)) {
                    continue;
                }

                $sentences[$sentence] = true;
            }
        }

        return array_keys($sentences);
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
    /**
     * Every group key a view passes to `__()`.
     *
     * The pattern used to read `[a-z0-9_.]+` after the group, which matched
     * only lowercase dotted keys — and **this codebase overwhelmingly uses
     * sentence keys**: `__('messages.Umrah Packages')`. So the check that
     * exists to prove every key resolves was silently skipping almost every
     * key on the site.
     *
     * That is not theoretical. Phase 9.4 shipped thirty-four new
     * `messages.` keys with no English entry; each rendered the literal
     * string `messages.Stays` on the page, and the page tests passed
     * because `assertSee('Stays')` matches it as a substring. A guard that
     * cannot see the thing it is named after reports green about something
     * else — the shape `AGENTS.md` records for `favicon.ico`.
     *
     * Now: anything up to the closing quote, minus the escapes a key cannot
     * contain.
     *
     * @return list<string>
     */
    private function groupKeysUsedInViews(): array
    {
        $keys = [];

        foreach ($this->bladeFiles() as $file) {
            preg_match_all("/__\('([a-z0-9_]+\.[^'\\\\]+)'/", File::get($file), $matches);

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
