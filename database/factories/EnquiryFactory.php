<?php

namespace Database\Factories;

use App\Models\Enquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enquiry>
 */
class EnquiryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'source' => Enquiry::WEB,
            'name' => $this->faker->name(),
            'phone' => '77'.$this->faker->numerify('#####'),
            'email' => $this->faker->safeEmail(),
            'message' => $this->faker->sentence(),
        ];
    }

    public function working(): static
    {
        return $this->state(fn (): array => ['status' => Enquiry::WORKING]);
    }
}
