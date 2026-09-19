<?php

namespace Tests\Feature;

use App\Models\GuideStep;
use Database\Seeders\UmrahGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Machine-generated filler, shipped as Dhivehi.
 *
 * The site's Dhivehi was not merely incomplete — parts of it were fabricated,
 * and the giveaway is always the same: many different English strings mapping
 * to one identical Dhivehi string.
 *
 * `resources/lang/dv/guide.php` held 19 keys with 3 distinct values. "Step",
 * "of", "Table of Contents", "Checklist", "Fiqh Notes", "Du'a", "Reference",
 * "Previous Step" and eight more all rendered as one word, which appeared 31
 * times on the live Dhivehi guide page. `dv/messages.php` had 99 keys sharing
 * a value with another key, including a file-upload instruction sharing one
 * with "Edit".
 *
 * Worst of all, the ten seeded Dhivehi guide steps carried the *same* du'a and
 * the same reference_text as each other, where the ten English steps carry ten
 * real supplications and citations. A Dhivehi pilgrim reading the du'a for
 * Niyyah, Ihram, Tawaf and Sa'i was given one meaningless sentence each time,
 * under a heading claiming it was the supplication for that rite.
 *
 * None of it was replaced with a paraphrase. Inventing religious text would be
 * a worse defect than the one being fixed.
 */
class TranslationQualityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two keys sharing a Dhivehi value is only wrong when the keys mean
     * different things in English. `en/messages.php` deliberately dual-keys
     * some strings — 'Manage Trips' and 'manage_trips' — and one Dhivehi value
     * covering such a pair is correct. Checking the English meaning behind each
     * key is what separates an alias from fabrication, and it spared eight real
     * translations a blunter rule would have deleted.
     */
    public function test_no_two_unrelated_keys_share_a_translation(): void
    {
        $offenders = [];

        foreach (File::directories(resource_path('lang')) as $localeDir) {
            $locale = basename($localeDir);

            if ($locale === config('app.fallback_locale')) {
                // Here the value *is* the English text, so aliases collide by
                // definition and prove nothing.
                continue;
            }

            foreach (File::files($localeDir) as $file) {
                $translated = require $file->getPathname();
                $englishPath = resource_path('lang/'.config('app.fallback_locale').'/'.$file->getFilename());
                $english = File::exists($englishPath) ? require $englishPath : [];

                if (! is_array($translated) || ! is_array($english)) {
                    continue;
                }

                $byValue = [];

                foreach ($translated as $key => $value) {
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
                        $offenders[] = sprintf('%s/%s: %d unrelated keys share "%s" (%s)',
                            $locale, $file->getFilename(), count($keys), $value,
                            implode(', ', array_slice($keys, 0, 5)));
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            'Unrelated keys rendering identically is how the fabricated Dhivehi was found.');
    }

    /**
     * Every guide step must have its own supplication. Ten steps sharing one
     * du'a is the defect that mattered most, because a pilgrim acts on it.
     */
    public function test_no_two_guide_steps_share_a_dua_or_a_reference(): void
    {
        $this->seed(UmrahGuideSeeder::class);

        foreach (['en', 'dv'] as $locale) {
            $steps = GuideStep::where('locale', $locale)->get();

            if ($steps->isEmpty()) {
                continue;
            }

            foreach (['dua_text', 'reference_text'] as $column) {
                $values = $steps->pluck($column)->filter()->values();

                $this->assertSame(
                    $values->count(),
                    $values->unique()->count(),
                    "[{$locale}] guide steps repeat the same {$column}; that is filler, not content.",
                );
            }
        }
    }

    /** The seeder must not put fabricated religious text back. */
    public function test_the_seeder_ships_no_dhivehi_guide_steps(): void
    {
        $this->seed(UmrahGuideSeeder::class);

        $this->assertSame(0, GuideStep::where('locale', 'dv')->count(),
            'Dhivehi guide content needs a translator and a scholar, not a seeder.');

        $this->assertGreaterThan(0, GuideStep::where('locale', 'en')->count());
    }

    /**
     * With no Dhivehi steps, a Dhivehi visitor must still get the guide —
     * correct, in English — rather than an empty page.
     */
    public function test_the_dhivehi_guide_falls_back_to_the_english_steps(): void
    {
        $this->seed(UmrahGuideSeeder::class);

        $this->get('/dv/guide')
            ->assertOk()
            ->assertSee('Intention (Niyyah)')
            ->assertSee('Labbaik', escape: false);
    }

    /** The same fallback, for the API and the PDF that share the query. */
    public function test_the_guide_api_falls_back_too(): void
    {
        $this->seed(UmrahGuideSeeder::class);

        $response = $this->getJson('/api/guide-steps?locale=dv')->assertOk();

        $this->assertNotEmpty($response->json(),
            'An empty guide is worse than a correct one in the wrong language.');
    }

    /**
     * A real Dhivehi translation, once it exists, must win over the fallback.
     */
    public function test_a_real_dhivehi_step_is_preferred_over_the_fallback(): void
    {
        $this->seed(UmrahGuideSeeder::class);

        GuideStep::create([
            'step_number' => 1,
            'locale' => 'dv',
            'title' => 'ނިއްޔާ',
            'summary' => 'ހަގީގީ ތަރުޖަމާ',
            'is_published' => true,
        ]);

        $this->get('/dv/guide')
            ->assertOk()
            ->assertSee('ނިއްޔާ', escape: false)
            ->assertDontSee('Intention (Niyyah)');
    }
}
