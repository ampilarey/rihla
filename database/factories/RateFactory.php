<?php

namespace Database\Factories;

use App\Models\Rate;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rate>
 */
class RateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'room_type_id' => RoomType::factory(),
            'starts_on' => now()->addMonth()->startOfMonth()->toDateString(),
            'ends_on' => now()->addMonth()->endOfMonth()->toDateString(),
            'rate_minor' => $this->faker->numberBetween(9000, 25000),
        ];
    }
}
