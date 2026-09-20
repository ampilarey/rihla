<?php

namespace Database\Factories;

use App\Models\DepartureHotel;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'departure_hotel_id' => DepartureHotel::factory(),
            'label' => (string) $this->faker->unique()->numberBetween(100, 999),
            'capacity' => 4,
            'gender' => Room::MALE,
        ];
    }

    public function beds(int $capacity): static
    {
        return $this->state(fn (): array => ['capacity' => $capacity]);
    }

    public function forWomen(): static
    {
        return $this->state(fn (): array => ['gender' => Room::FEMALE]);
    }

    public function family(): static
    {
        return $this->state(fn (): array => ['gender' => Room::FAMILY]);
    }

    public function undesignated(): static
    {
        return $this->state(fn (): array => ['gender' => null]);
    }
}
