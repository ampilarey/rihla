<?php

namespace Database\Factories;

use App\Models\Departure;
use App\Models\DepartureHotel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartureHotel>
 */
class DepartureHotelFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'departure_id' => Departure::factory(),
            'city' => DepartureHotel::CITY_MAKKAH,
            'name' => $this->faker->company().' Hotel',
            'distance_metres' => $this->faker->numberBetween(100, 1200),
            'walk_minutes' => $this->faker->numberBetween(2, 18),
            'nights' => 7,
            'sort_order' => 1,
        ];
    }

    public function inMadinah(): static
    {
        return $this->state(fn (): array => [
            'city' => DepartureHotel::CITY_MADINAH,
            'sort_order' => 2,
        ]);
    }
}
