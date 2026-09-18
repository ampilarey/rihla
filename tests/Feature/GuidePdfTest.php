<?php

namespace Tests\Feature;

use App\Models\GuideStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The downloadable guide, which is the artefact a pilgrim actually carries.
 *
 * Its stylesheet pointed at storage/fonts/Faruma.ttf, a file that has never
 * existed. dompdf does not complain about a missing @font-face source; it
 * falls back. The fallback has no Thaana glyphs, so the Dhivehi guide
 * downloaded as boxes — and a PDF cannot be fixed by reloading the page.
 */
class GuidePdfTest extends TestCase
{
    use RefreshDatabase;

    private function seedStep(string $locale, string $title): void
    {
        GuideStep::factory()->create([
            'step_number' => 1,
            'locale' => $locale,
            'title' => $title,
            'summary' => 'Enter the state of Ihram at the miqat.',
            'is_published' => true,
        ]);
    }

    /**
     * Every font the template names must be on disk. This is the assertion
     * that was missing: the path was wrong for as long as the file existed
     * somewhere else, and nothing compared the two.
     */
    public function test_every_font_the_pdf_references_exists(): void
    {
        $template = File::get(resource_path('views/pdf/guide.blade.php'));

        preg_match_all("/(?:public_path|storage_path)\('([^']+)'\)/", $template, $matches);

        $this->assertNotEmpty($matches[1], 'The PDF template references no font file at all.');

        foreach ($matches[1] as $relative) {
            $path = str_contains($template, "storage_path('{$relative}')")
                ? storage_path($relative)
                : public_path($relative);

            $this->assertFileExists($path, "The PDF references {$relative}, which does not exist.");
        }
    }

    public function test_the_english_guide_downloads(): void
    {
        $this->seedStep('en', 'Ihram');

        $response = $this->get('/en/guide/pdf')->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * The Dhivehi guide must embed the Thaana face. Without it dompdf still
     * produces a valid PDF — it just cannot draw the script, which is why
     * this went unnoticed: the download worked, the contents did not.
     */
    public function test_the_dhivehi_guide_embeds_the_thaana_font(): void
    {
        $this->seedStep('dv', 'އިޙްރާމް');

        $response = $this->get('/dv/guide/pdf')->assertOk();

        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);

        // dompdf names each embedded face in the PDF's font descriptors.
        $this->assertStringContainsString(
            'Faruma',
            $pdf,
            'The Dhivehi PDF does not embed the Thaana font, so its text cannot render.',
        );
    }
}
