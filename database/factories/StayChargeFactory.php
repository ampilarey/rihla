<?php

namespace Database\Factories;

use App\Models\Stay;
use App\Models\StayCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StayCharge> */
class StayChargeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory(),
            'kind' => StayCharge::EXTRA,
            'description' => 'Airport speedboat transfer',
            'quantity' => 1,
            'unit_minor' => 5000,
            'currency' => 'USD',
        ];
    }
}
