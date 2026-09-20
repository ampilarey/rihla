<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerTag> */
class CustomerTagFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'tag' => 'placeholder-tag',
        ];
    }
}
