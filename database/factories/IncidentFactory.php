<?php

namespace Database\Factories;

use App\Models\Departure;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'departure_id' => Departure::factory(),
            'severity' => Incident::MINOR,
            'category' => Incident::OTHER,
            'summary' => $this->faker->sentence(6),
            'happened_at' => now()->subHours(2),
        ];
    }

    public function emergency(): static
    {
        return $this->state(fn (): array => ['severity' => Incident::EMERGENCY]);
    }

    public function serious(): static
    {
        return $this->state(fn (): array => ['severity' => Incident::SERIOUS]);
    }

    public function medical(): static
    {
        return $this->state(fn (): array => ['category' => Incident::MEDICAL]);
    }
}
