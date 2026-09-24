<?php

namespace Tests\Feature;

use App\Filament\Resources\GuideSteps\Pages\CreateGuideStep;
use App\Filament\Resources\GuideSteps\Pages\EditGuideStep;
use App\Filament\Resources\GuideSteps\Pages\ListGuideSteps;
use App\Models\GuideStep;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One guide step, both languages.
 *
 * `guide_steps` kept a `locale` column and one row per language, so step 3 of
 * the Umrah guide was two unrelated records agreeing only by convention on
 * their `step_number`. Nothing joined them: publishing the English step said
 * nothing about the Dhivehi one, reordering had to be done twice, and — the
 * part a pilgrim saw — a single missing Dhivehi step sent the *whole* guide
 * back to English rather than that one step.
 */
class GuideStepTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../database/migrations/2026_09_19_140000_make_guide_steps_translatable.php';

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    /**
     * The staff panel's create form (§9.2), which replaced the Blade one.
     *
     * @param  array<string, mixed>  $data
     */
    private function create(array $data): Testable
    {
        return Livewire::actingAs($this->admin())
            ->test(CreateGuideStep::class)
            ->fillForm($data)
            ->call('create');
    }

    public function test_the_locale_column_is_gone(): void
    {
        $this->assertNotContains('locale', Schema::getColumnListing('guide_steps'));
    }

    /**
     * The merge, against the shape it had to read. No environment has Dhivehi
     * rows — they were deleted as machine-generated filler — so this is the
     * only place the two-row case is exercised at all.
     */
    public function test_the_migration_merges_the_two_locale_rows(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => true])
            ->assertSuccessful();

        $base = [
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'details' => null,
            'video_url' => null,
            'image_path' => null,
            'dua_text' => null,
            'reference_text' => null,
            'fiqh_notes' => null,
            'checklist' => null,
        ];

        DB::table('guide_steps')->insert([
            array_merge($base, [
                'step_number' => 1, 'locale' => 'en',
                'title' => 'Intention', 'summary' => 'Make the intention.',
                'dua_text' => 'Allahumma inni uridu al-umrata',
                'image_path' => 'guide/step-1.webp',
                'checklist' => json_encode(['Make sincere intention']),
            ]),
            array_merge($base, [
                'step_number' => 1, 'locale' => 'dv',
                'title' => 'ނިއްޔާ', 'summary' => 'ހަގީގީ ތަރުޖަމާ',
                'checklist' => json_encode(['ނިޔަތް ގަންނާށެވެ']),
            ]),
            array_merge($base, [
                'step_number' => 2, 'locale' => 'en',
                'title' => 'Ihram', 'summary' => 'Enter the state of Ihram.',
            ]),
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION, '--realpath' => true])
            ->assertSuccessful();

        $this->assertSame(2, GuideStep::count(), 'The two rows for step 1 did not become one.');

        $first = GuideStep::where('step_number', 1)->sole();

        $this->assertSame('Intention', $first->getTranslation('title', 'en'));
        $this->assertSame('ނިއްޔާ', $first->getTranslation('title', 'dv'));
        $this->assertSame(['Make sincere intention'], $first->getTranslation('checklist', 'en'));
        $this->assertSame(['ނިޔަތް ގަންނާށެވެ'], $first->getTranslation('checklist', 'dv'));

        // The English row's image and du'a are the step's, not one language's.
        $this->assertSame('guide/step-1.webp', $first->image_path);
        $this->assertSame('Allahumma inni uridu al-umrata', $first->dua_text);

        $second = GuideStep::where('step_number', 2)->sole();
        $this->assertSame('Ihram', $second->getTranslation('title', 'en'));
        $this->assertFalse($second->hasTranslation('title', 'dv'));
    }

    public function test_one_row_serves_both_languages(): void
    {
        GuideStep::create([
            'step_number' => 1,
            'title' => ['en' => 'Intention', 'dv' => 'ނިއްޔާ'],
            'summary' => ['en' => 'Make the intention.', 'dv' => 'ނިޔަތް ގަންނާށެވެ'],
            'is_published' => true,
        ]);

        $this->get('/en/guide')->assertOk()
            ->assertSee('Intention')
            ->assertDontSee('ނިއްޔާ', false);

        $this->get('/dv/guide')->assertOk()
            ->assertSee('ނިއްޔާ', false)
            ->assertDontSee('Intention');
    }

    /**
     * The behaviour change this migration exists for: the fallback is now per
     * field. A step translated by halves used to be impossible — and a single
     * untranslated step sent the entire guide back to English.
     */
    public function test_the_fallback_is_per_field_not_per_guide(): void
    {
        GuideStep::create([
            'step_number' => 1,
            'title' => ['en' => 'Intention', 'dv' => 'ނިއްޔާ'],
            'summary' => ['en' => 'Make the intention before the miqat.'],
            'is_published' => true,
        ]);

        GuideStep::create([
            'step_number' => 2,
            'title' => ['en' => 'Ihram'],
            'summary' => ['en' => 'Enter the state of Ihram.'],
            'is_published' => true,
        ]);

        $this->get('/dv/guide')->assertOk()
            // Translated where it exists…
            ->assertSee('ނިއްޔާ', false)
            // …English for the rest of the same step…
            ->assertSee('Make the intention before the miqat.')
            // …and the untranslated step still appears rather than taking the
            // whole guide down with it.
            ->assertSee('Ihram');
    }

    public function test_the_admin_form_saves_both_languages_in_one_submit(): void
    {
        $this->create([
            'step_number' => 1,
            'title' => ['en' => 'Intention', 'dv' => 'ނިއްޔާ'],
            'summary' => ['en' => 'Make the intention.', 'dv' => 'ނިޔަތް ގަންނާށެވެ'],
            'dua_text' => 'Allahumma inni uridu al-umrata',
            // One item per line, per language.
            'checklist' => ['en' => "Make sincere intention\nFocus on purpose", 'dv' => ''],
            'fiqh_notes' => ['en' => "Obligatory in all four schools\n\nMust precede Ihram"],
            'is_published' => true,
        ])->assertHasNoFormErrors();

        $step = GuideStep::sole();

        $this->assertSame('ނިއްޔާ', $step->getTranslation('title', 'dv'));
        $this->assertSame(['Make sincere intention', 'Focus on purpose'], $step->getTranslation('checklist', 'en'));
        // Blank lines are separators, not items.
        $this->assertSame(['Obligatory in all four schools', 'Must precede Ihram'], $step->getTranslation('fiqh_notes', 'en'));
        // An empty box is not a translation.
        $this->assertFalse($step->hasTranslation('checklist', 'dv'));
    }

    /**
     * The old request made every Dhivehi field required the moment the step
     * was Dhivehi, for text the guide could not show.
     */
    public function test_dhivehi_is_never_required(): void
    {
        $this->create([
            'step_number' => 1,
            'title' => ['en' => 'Intention', 'dv' => ''],
            'summary' => ['en' => 'Make the intention.', 'dv' => ''],
            'is_published' => true,
        ])->assertHasNoFormErrors();

        $this->assertSame(1, GuideStep::count());
    }

    public function test_english_is_required(): void
    {
        $this->create([
            'step_number' => 1,
            'title' => ['en' => '', 'dv' => 'ނިއްޔާ'],
            'summary' => ['en' => 'Make the intention.'],
        ])->assertHasFormErrors(['title.en' => 'required']);

        $this->assertSame(0, GuideStep::count());
    }

    /**
     * The du'a is the Arabic of the rite, not a translation of anything — one
     * field, shown unchanged in both languages.
     */
    public function test_the_dua_is_one_value_for_both_languages(): void
    {
        GuideStep::create([
            'step_number' => 1,
            'title' => ['en' => 'Intention', 'dv' => 'ނިއްޔާ'],
            'summary' => ['en' => 'Make the intention.'],
            'dua_text' => 'Allahumma inni uridu al-umrata',
            'is_published' => true,
        ]);

        foreach (['en', 'dv'] as $locale) {
            $this->get("/{$locale}/guide")->assertOk()->assertSee('Allahumma inni uridu al-umrata');
        }
    }

    /**
     * A list attribute with nothing stored for this locale reads back as an
     * empty *string*, which is neither null nor an array — and the API has
     * always promised an array.
     */
    public function test_the_api_returns_lists_even_when_untranslated(): void
    {
        GuideStep::create([
            'step_number' => 1,
            'title' => ['en' => 'Intention'],
            'summary' => ['en' => 'Make the intention.'],
            'is_published' => true,
        ]);

        $response = $this->getJson('/api/guide-steps?locale=dv')->assertOk();

        $this->assertSame([], $response->json('steps.0.checklist'));
        $this->assertSame([], $response->json('steps.0.fiqh_notes'));
    }

    /**
     * Two rows sharing a step number used to be *how* a step was translated,
     * so the uniqueness rule the form request had always carried could never
     * be switched on. It can now.
     */
    public function test_two_steps_cannot_share_a_number(): void
    {
        GuideStep::create([
            'step_number' => 1,
            'title' => ['en' => 'Intention'],
            'summary' => ['en' => 'Make the intention.'],
            'is_published' => true,
        ]);

        $this->create([
            'step_number' => 1,
            'title' => ['en' => 'Ihram'],
            'summary' => ['en' => 'Enter the state of Ihram.'],
        ])->assertHasFormErrors(['step_number' => 'unique']);

        $this->assertSame(1, GuideStep::count());
    }

    /** The panel lists every step once, whatever languages it is written in. */
    public function test_the_panel_lists_each_step_once(): void
    {
        GuideStep::create([
            'step_number' => 1,
            'title' => ['en' => 'Intention', 'dv' => 'ނިއްޔާ'],
            'summary' => ['en' => 'Make the intention.'],
            'is_published' => true,
        ]);

        Livewire::actingAs($this->admin())
            ->test(ListGuideSteps::class)
            ->assertCountTableRecords(1)
            ->assertSeeText('Intention');
    }

    /**
     * Deleting a Dhivehi line in the panel used to leave it stored: the form
     * dropped the blank locale, and assigning `{"en": …}` to a translatable
     * attribute merges into what is there. The only way to remove a
     * translation was to type a different one over it — on the guide, where
     * AGENTS.md records machine-made Dhivehi that had to be taken out.
     */
    public function test_blanking_the_dhivehi_removes_it(): void
    {
        $step = GuideStep::create([
            'step_number' => 1,
            'title' => ['en' => 'Intention', 'dv' => 'ނިއްޔާ'],
            'summary' => ['en' => 'Make the intention.', 'dv' => 'ނިޔަތް ގަންނާށެވެ'],
            'checklist' => ['en' => ['Make sincere intention'], 'dv' => ['ނިޔަތް']],
            'is_published' => true,
        ]);

        Livewire::actingAs($this->admin())
            ->test(EditGuideStep::class, ['record' => $step->getKey()])
            ->fillForm(['title' => ['dv' => ''], 'summary' => ['dv' => ''], 'checklist' => ['dv' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $step->refresh();

        $this->assertFalse($step->hasTranslation('title', 'dv'), 'The Dhivehi title an editor deleted is still there.');
        $this->assertFalse($step->hasTranslation('summary', 'dv'));
        $this->assertFalse($step->hasTranslation('checklist', 'dv'));
        // The English is untouched, and is what a Dhivehi reader now gets.
        $this->assertSame('Intention', $step->getTranslation('title', 'en'));
        $this->assertSame('Intention', $step->getTranslation('title', 'dv'));
        $this->assertSame(['Make sincere intention'], $step->getTranslation('checklist', 'dv'));
    }
}
