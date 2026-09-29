<?php

namespace Database\Factories;

use App\Models\HostPage;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HostPage> */
class HostPageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'layout' => HostPage::STORY,
            'tagline' => ['en' => 'Two minutes from the harbour.'],
            'about' => ['en' => 'A family guesthouse on a quiet island.'],
        ];
    }

    public function published(): static
    {
        return $this->afterCreating(fn (HostPage $page) => $page->forceFill(['published_at' => now()])->save());
    }
}
