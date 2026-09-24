<?php

namespace Database\Factories;

use App\Models\Stay;
use App\Models\StayGuest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StayGuest>
 */
class StayGuestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory(),
            'full_name' => $this->faker->name(),
            'nationality' => $this->faker->randomElement(['Maldivian', 'British', 'German', 'Italian']),
            'date_of_birth' => $this->faker->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'id_type' => StayGuest::PASSPORT,
            'id_number' => strtoupper($this->faker->bothify('??######')),
            'is_lead' => false,
        ];
    }

    public function lead(): static
    {
        return $this->state(fn (): array => ['is_lead' => true]);
    }

    public function maldivian(): static
    {
        return $this->state(fn (): array => [
            'nationality' => 'Maldivian',
            'id_type' => StayGuest::NATIONAL_ID,
            'id_number' => 'A'.$this->faker->numerify('######'),
        ]);
    }
}
