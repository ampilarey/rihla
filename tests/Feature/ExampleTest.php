<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // The homepage queries trips, media and settings, so it cannot render
    // against whatever happens to be in the developer's database.
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        // The bare domain forwards to a localised URL; the homepage itself
        // lives at /en.
        $this->get('/')->assertRedirect('/en');

        $this->get('/en')->assertStatus(200);
    }
}
