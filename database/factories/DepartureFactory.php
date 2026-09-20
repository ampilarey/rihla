<?php

namespace Database\Factories;

use App\Models\Departure;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Departure>
 */
class DepartureFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('+2 weeks', '+8 months');

        return [
            'package_id' => Package::factory(),
            'date_start' => $start,
            'date_end' => (clone $start)->modify('+14 days'),
            'airline' => $this->faker->randomElement(['Maldivian', 'Emirates', 'Qatar Airways', 'SriLankan']),
            'capacity_total' => 24,
            'capacity_held' => 0,
            'capacity_confirmed' => $this->faker->numberBetween(0, 20),
            'status' => Departure::STATUS_UPCOMING,
            'is_published' => true,
        ];
    }

    public function soldOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'capacity_confirmed' => $attributes['capacity_total'] ?? 24,
        ]);
    }

    /**
     * A known number of empty seats.
     *
     * The default state confirms a random number of seats, which is right
     * for a page that draws a seats-remaining bar and useless for a test
     * about capacity arithmetic.
     */
    public function withSeats(int $total): static
    {
        return $this->state(fn (): array => [
            'capacity_total' => $total,
            'capacity_held' => 0,
            'capacity_confirmed' => 0,
        ]);
    }

    /** No capacity recorded, which is how a backfilled departure starts. */
    public function withoutCapacity(): static
    {
        return $this->state(fn (): array => [
            'capacity_total' => 0,
            'capacity_held' => 0,
            'capacity_confirmed' => 0,
        ]);
    }
}
