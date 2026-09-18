<?php

namespace Tests\Feature;

use App\Models\GuideStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guide_page_loads_with_steps()
    {
        // Create test steps
        GuideStep::factory()->create([
            'locale' => 'en',
            'step_number' => 1,
            'title' => 'Test Step 1',
            'summary' => 'Test summary 1',
            'is_published' => true,
        ]);

        GuideStep::factory()->create([
            'locale' => 'en',
            'step_number' => 2,
            'title' => 'Test Step 2',
            'summary' => 'Test summary 2',
            'is_published' => true,
        ]);

        $response = $this->get('/en/guide');
        $response->assertStatus(200);
        $response->assertSee('Test Step 1');
        $response->assertSee('Test Step 2');
    }

    public function test_guide_pdf_download()
    {
        GuideStep::factory()->create([
            'locale' => 'en',
            'step_number' => 1,
            'title' => 'Test Step',
            'summary' => 'Test summary',
            'is_published' => true,
        ]);

        $response = $this->get('/en/guide/pdf');
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_guide_api_returns_steps()
    {
        GuideStep::factory()->create([
            'locale' => 'en',
            'step_number' => 1,
            'title' => 'Test Step',
            'summary' => 'Test summary',
            'is_published' => true,
        ]);

        $response = $this->get('/api/guide-steps?locale=en');
        $response->assertStatus(200);
        $response->assertJson([
            'locale' => 'en',
            'total_steps' => 1,
        ]);
        $response->assertJsonPath('steps.0.title', 'Test Step');
    }

    public function test_guide_api_locale_validation()
    {
        $response = $this->get('/api/guide-steps?locale=invalid');
        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid locale']);
    }

    public function test_guide_steps_ordering()
    {
        GuideStep::factory()->create([
            'locale' => 'en',
            'step_number' => 3,
            'title' => 'Step 3',
            'is_published' => true,
        ]);

        GuideStep::factory()->create([
            'locale' => 'en',
            'step_number' => 1,
            'title' => 'Step 1',
            'is_published' => true,
        ]);

        GuideStep::factory()->create([
            'locale' => 'en',
            'step_number' => 2,
            'title' => 'Step 2',
            'is_published' => true,
        ]);

        $response = $this->get('/en/guide');
        $response->assertStatus(200);

        // Check order in response
        $response->assertSeeInOrder(['Step 1', 'Step 2', 'Step 3']);
    }

    public function test_unpublished_steps_not_shown()
    {
        GuideStep::factory()->create([
            'locale' => 'en',
            'step_number' => 1,
            'title' => 'Published Step',
            'is_published' => true,
        ]);

        GuideStep::factory()->create([
            'locale' => 'en',
            'step_number' => 2,
            'title' => 'Unpublished Step',
            'is_published' => false,
        ]);

        $response = $this->get('/en/guide');
        $response->assertStatus(200);
        $response->assertSee('Published Step');
        $response->assertDontSee('Unpublished Step');
    }
}
