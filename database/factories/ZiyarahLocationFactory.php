<?php

namespace Database\Factories;

use App\Models\ZiyarahLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ZiyarahLocation>
 *
 * Deliberately neutral placeholder text. This factory must never carry
 * religious content or a real place name: a fixture that reads like a real
 * location page is one that ends up copied into a seeder, and AGENTS.md
 * records what that has already cost this codebase.
 */
class ZiyarahLocationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Location '.Str::random(6);

        return [
            'slug' => Str::slug($name),
            'name' => ['en' => $name],
            'summary' => ['en' => 'Placeholder summary for a test fixture.'],
            'history' => ['en' => 'Placeholder history for a test fixture.'],
            'significance' => ['en' => 'Placeholder significance for a test fixture.'],
            'etiquette' => ['en' => 'Placeholder etiquette for a test fixture.'],
            'best_time' => ['en' => 'Placeholder timing note for a test fixture.'],
            'city' => ZiyarahLocation::MAKKAH,
            'sort_order' => 0,
        ];
    }
}
