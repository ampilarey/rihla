<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomType>
 */
class RoomTypeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => ['en' => $this->faker->randomElement(['Double Room', 'Twin Room', 'Family Room', 'Sea View Double'])],
            'description' => ['en' => $this->faker->sentence()],
            'sleeps' => 2,
            'beds' => '1 double',
            'size_m2' => $this->faker->numberBetween(14, 32),
            'amenities' => ['en' => ['Air conditioning', 'Private bathroom']],
            'quantity' => 2,
            // Minor units — cents, because a guesthouse is quoted in USD.
            'base_rate_minor' => $this->faker->numberBetween(4000, 18000),
            'sort_order' => 0,
        ];
    }
}
