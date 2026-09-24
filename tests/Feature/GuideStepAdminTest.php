<?php

namespace Tests\Feature;

use App\Filament\Resources\GuideSteps\GuideStepResource;
use App\Filament\Resources\GuideSteps\Pages\CreateGuideStep;
use App\Filament\Resources\GuideSteps\Pages\EditGuideStep;
use App\Models\GuideStep;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\UmrahGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The guide_steps table and the code using it never agreed.
 *
 * The table had one body-text column, `description`. The model, the admin
 * controller, the public guide, the PDF export and the JSON API all expected
 * `summary`, `details`, `video_url` and `image_path`, none of which existed.
 * Creating a step therefore threw "no column named summary" and returned a
 * 500 — the Umrah guide could not be edited at all — while every published
 * step rendered its title followed by nothing.
 *
 * Nothing asserted either half, so both shipped.
 *
 * The admin half now drives the staff panel's form (§9.2); the Blade
 * screen and its routes are gone, and `/admin/guide-steps` forwards.
 */
class GuideStepAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    /** @return array<string, mixed> */
    private function validStep(array $overrides = []): array
    {
        return array_merge([
            'step_number' => 1,
            'title' => ['en' => 'Ihram'],
            'summary' => ['en' => 'Enter the state of Ihram at the miqat.'],
            'details' => ['en' => 'Bathe, wear the two white sheets, and make your intention.'],
            'dua_text' => 'Labbayka Allahumma labbayk.',
            'reference_text' => ['en' => 'Quran 2:196 and authentic hadith.'],
            'fiqh_notes' => ['en' => 'Obligatory in all four schools'],
            'checklist' => ['en' => "Perform ghusl\nRecite the Talbiyah"],
            'is_published' => true,
        ], $overrides);
    }

    public function test_an_admin_can_create_a_guide_step(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateGuideStep::class)
            ->fillForm($this->validStep())
            ->call('create')
            ->assertHasNoFormErrors();

        $step = GuideStep::sole();

        $this->assertSame('Ihram', $step->title);
        $this->assertSame('Enter the state of Ihram at the miqat.', $step->summary);
        $this->assertSame(
            'Bathe, wear the two white sheets, and make your intention.',
            $step->details,
        );
        // The column existed and was seeded from the start, but no request
        // could reach it: the admin form had no field for it.
        $this->assertSame('Quran 2:196 and authentic hadith.', $step->reference_text);
    }

    public function test_an_admin_can_update_a_guide_step(): void
    {
        $step = GuideStep::factory()->create(['title' => 'Old title']);

        Livewire::actingAs($this->admin())
            ->test(EditGuideStep::class, ['record' => $step->getKey()])
            ->fillForm($this->validStep(['title' => ['en' => 'New title'], 'summary' => ['en' => 'Rewritten.']]))
            ->call('save')
            ->assertHasNoFormErrors();

        $step->refresh();

        $this->assertSame('New title', $step->title);
        $this->assertSame('Rewritten.', $step->summary);
    }

    /**
     * fiqh_notes is one note per school of thought. The model casts it to an
     * array, the seeder and the admin form both supply an array — but an
     * accessor named after the attribute read the attribute itself, shadowing
     * the cast, so it always came back empty. Notes were saved and could never
     * be read, which is why they never appeared on the guide.
     */
    public function test_fiqh_notes_survive_a_round_trip(): void
    {
        $step = GuideStep::factory()->create([
            'fiqh_notes' => ['Hanafi: before departure', 'Shafi\'i: at the miqat'],
        ]);

        $step->refresh();

        $this->assertSame(
            ['Hanafi: before departure', 'Shafi\'i: at the miqat'],
            $step->fiqh_notes,
        );
        $this->assertTrue($step->hasFiqhNotes());
    }

    /**
     * `reference_text` was seeded for every step, is in $fillable, and has a
     * translated label — and no view rendered it. Content stored and never
     * shown. For a licensed Umrah operator, the source of each ritual
     * instruction is the credibility.
     */
    public function test_the_guide_shows_the_source_of_each_step(): void
    {
        GuideStep::factory()->create([
            'step_number' => 1,
            'reference_text' => 'Quran 2:196 and authentic hadith about Ihram.',
            'is_published' => true,
        ]);

        $this->get('/en/guide')
            ->assertOk()
            ->assertSee('Quran 2:196 and authentic hadith about Ihram.');

        $this->get('/en/guide/pdf')->assertOk();
    }

    /**
     * The admin form is a textarea, one note per line; the column is a JSON
     * array. Validating as `array` while the form posts a string rejected
     * every note an editor typed, and rendering the array straight back into
     * the textarea raised "htmlspecialchars(): must be of type string, array
     * given" — a 500 on the edit screen.
     */
    public function test_fiqh_notes_round_trip_through_the_form(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateGuideStep::class)
            ->fillForm($this->validStep([
                'fiqh_notes' => ['en' => "Hanafi: before departure\nShafi'i: at the miqat\n\n"],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $step = GuideStep::sole();

        // Split per line, blank lines dropped.
        $this->assertSame(
            ['Hanafi: before departure', "Shafi'i: at the miqat"],
            $step->fiqh_notes,
        );

        // And the edit screen renders them back, one per line, without
        // throwing.
        Livewire::actingAs($this->admin())
            ->test(EditGuideStep::class, ['record' => $step->getKey()])
            ->assertFormSet(['fiqh_notes.en' => "Hanafi: before departure\nShafi'i: at the miqat"]);

        $this->actingAs($this->admin())
            ->get(GuideStepResource::getUrl('edit', ['record' => $step]))
            ->assertOk()
            ->assertSee('Hanafi: before departure');
    }

    public function test_the_public_guide_renders_a_step_body_not_just_its_title(): void
    {
        GuideStep::factory()->create([
            'step_number' => 1,
            'title' => 'Ihram',
            'summary' => 'Enter the state of Ihram at the miqat.',
            'details' => 'Bathe, wear the two white sheets.',
            'dua_text' => 'Labbayka Allahumma labbayk.',
            'fiqh_notes' => ['Obligatory in all four schools'],
            'is_published' => true,
        ]);

        $this->get('/en/guide')
            ->assertOk()
            ->assertSee('Ihram')
            ->assertSee('Enter the state of Ihram at the miqat.')
            ->assertSee('Bathe, wear the two white sheets.')
            ->assertSee('Labbayka Allahumma labbayk.')
            ->assertSee('Obligatory in all four schools');
    }

    /**
     * An array rendered with {{ }} raises "Array to string conversion". The
     * guide only escaped that because the notes were unreadable in the first
     * place; once they could be read, the page had to be able to show them.
     */
    public function test_the_pdf_export_renders_fiqh_notes(): void
    {
        GuideStep::factory()->create([
            'step_number' => 1,
            'fiqh_notes' => ['Obligatory in all four schools'],
            'is_published' => true,
        ]);

        $this->get('/en/guide/pdf')->assertOk();
    }

    public function test_the_old_addresses_forward_to_the_new_one(): void
    {
        $step = GuideStep::factory()->create();

        foreach (['/admin/guide-steps', '/admin/guide-steps/create', "/admin/guide-steps/{$step->id}/edit"] as $old) {
            $this->actingAs($this->admin())->get($old)->assertRedirect(GuideStepResource::getUrl('index'));
        }
    }

    /**
     * Each line is its own item, so the limit the Blade request held per
     * item (`checklist.*` max 255) is checked per line, not on the box.
     */
    public function test_a_checklist_line_too_long_is_refused(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateGuideStep::class)
            ->fillForm($this->validStep(['checklist' => ['en' => "Short\n".str_repeat('a', 256)]]))
            ->call('create')
            ->assertHasFormErrors(['checklist.en']);

        $this->assertSame(0, GuideStep::count());
    }

    public function test_the_seeded_guide_has_readable_content(): void
    {
        $this->seed(UmrahGuideSeeder::class);

        $step = GuideStep::orderBy('step_number')->first();

        $this->assertNotNull($step);
        $this->assertNotEmpty($step->summary, 'Seeded guide steps have no body text.');
    }
}
