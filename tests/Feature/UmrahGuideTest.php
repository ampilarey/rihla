<?php

namespace Tests\Feature;

use App\Models\GuideStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UmrahGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guide_page_displays_published_steps()
    {
        // Create test guide steps
        GuideStep::create([
            'step_number' => 1,
            'locale' => 'en',
            'title' => 'Test Step 1',
            'description' => 'Test description 1',
            'is_published' => true,
        ]);

        GuideStep::create([
            'step_number' => 2,
            'locale' => 'en',
            'title' => 'Test Step 2',
            'description' => 'Test description 2',
            'is_published' => false,
        ]);

        $response = $this->get('/en/guide');

        $response->assertStatus(200);
        $response->assertSee('Test Step 1');
        $response->assertDontSee('Test Step 2');
    }

    public function test_guide_page_shows_correct_locale_content()
    {
        // Create English step
        GuideStep::create([
            'step_number' => 1,
            'locale' => 'en',
            'title' => 'English Step',
            'description' => 'English description',
            'is_published' => true,
        ]);

        // Create Dhivehi step
        GuideStep::create([
            'step_number' => 1,
            'locale' => 'dv',
            'title' => 'ދިވެހިންނަށްޓަކައިންނެވެ',
            'description' => 'ދިވެހިންނަށްޓަކައިންނެވެ',
            'is_published' => true,
        ]);

        // Test English locale
        $response = $this->get('/en/guide');
        $response->assertStatus(200);
        $response->assertSee('English Step');
        $response->assertDontSee('ދިވެހިންނަށްޓަކައިންނެވެ');

        // Test Dhivehi locale. The language is now part of the URL, so the
        // page is requested directly rather than flipped via the session.
        $response = $this->get('/dv/guide');
        $response->assertStatus(200);
        $response->assertSee('ދިވެހިންނަށްޓަކައިންނެވެ');
        $response->assertDontSee('English Step');
    }

    public function test_guide_steps_are_ordered_correctly()
    {
        GuideStep::create([
            'step_number' => 3,
            'locale' => 'en',
            'title' => 'Step 3',
            'description' => 'Third step',
            'is_published' => true,
        ]);

        GuideStep::create([
            'step_number' => 1,
            'locale' => 'en',
            'title' => 'Step 1',
            'description' => 'First step',
            'is_published' => true,
        ]);

        GuideStep::create([
            'step_number' => 2,
            'locale' => 'en',
            'title' => 'Step 2',
            'description' => 'Second step',
            'is_published' => true,
        ]);

        $response = $this->get('/en/guide');

        $response->assertStatus(200);

        // Check that steps appear in correct order
        $content = $response->getContent();
        $pos1 = strpos($content, 'Step 1');
        $pos2 = strpos($content, 'Step 2');
        $pos3 = strpos($content, 'Step 3');

        $this->assertLessThan($pos2, $pos1);
        $this->assertLessThan($pos3, $pos2);
    }
}
