<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Traveller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Traveller>
 */
class TravellerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'full_name' => $this->faker->name(),
            'date_of_birth' => $this->faker->dateTimeBetween('-70 years', '-18 years'),
            'gender' => $this->faker->randomElement(Traveller::GENDERS),
            'relationship' => 'self',
            'nationality' => 'MV',
        ];
    }

    public function child(): static
    {
        return $this->state(fn (): array => [
            'date_of_birth' => $this->faker->dateTimeBetween('-11 years', '-3 years'),
            'relationship' => 'child',
        ]);
    }
}
