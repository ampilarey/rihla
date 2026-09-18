<?php

namespace Tests\Feature;

use App\Models\GuideStep;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\UmrahGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'locale' => 'en',
            'title' => 'Ihram',
            'summary' => 'Enter the state of Ihram at the miqat.',
            'details' => 'Bathe, wear the two white sheets, and make your intention.',
            'dua_text' => 'Labbayka Allahumma labbayk.',
            'fiqh_notes' => ['Obligatory in all four schools'],
            'checklist' => ['Perform ghusl', 'Recite the Talbiyah'],
            'is_published' => '1',
        ], $overrides);
    }

    public function test_an_admin_can_create_a_guide_step(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.guide-steps.store'), $this->validStep())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $step = GuideStep::sole();

        $this->assertSame('Ihram', $step->title);
        $this->assertSame('Enter the state of Ihram at the miqat.', $step->summary);
        $this->assertSame(
            'Bathe, wear the two white sheets, and make your intention.',
            $step->details,
        );
    }

    public function test_an_admin_can_update_a_guide_step(): void
    {
        $step = GuideStep::factory()->create(['title' => 'Old title']);

        $this->actingAs($this->admin())
            ->put(
                route('admin.guide-steps.update', $step),
                $this->validStep(['title' => 'New title', 'summary' => 'Rewritten.']),
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect();

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

    public function test_the_public_guide_renders_a_step_body_not_just_its_title(): void
    {
        GuideStep::factory()->create([
            'step_number' => 1,
            'locale' => 'en',
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
            'locale' => 'en',
            'fiqh_notes' => ['Obligatory in all four schools'],
            'is_published' => true,
        ]);

        $this->get('/en/guide/pdf')->assertOk();
    }

    public function test_the_seeded_guide_has_readable_content(): void
    {
        $this->seed(UmrahGuideSeeder::class);

        $step = GuideStep::where('locale', 'en')->orderBy('step_number')->first();

        $this->assertNotNull($step);
        $this->assertNotEmpty($step->summary, 'Seeded guide steps have no body text.');
    }
}
