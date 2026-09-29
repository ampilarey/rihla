<?php

namespace Database\Factories;

use App\Models\PropertyUnit;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PropertyUnit> */
class PropertyUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_type_id' => RoomType::factory(),
            // The unit belongs to the same building as its room type.
            'property_id' => fn (array $attributes): int => RoomType::findOrFail($attributes['room_type_id'])->property_id,
            'label' => 'Room '.$this->faker->unique()->numberBetween(1, 999),
            'housekeeping' => PropertyUnit::CLEAN,
            'is_active' => true,
        ];
    }
}
