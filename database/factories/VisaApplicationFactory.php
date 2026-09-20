<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Traveller;
use App\Models\VisaApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisaApplication>
 */
class VisaApplicationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'traveller_id' => Traveller::factory(),
            'attempt' => 1,
            'visa_type' => 'umrah',
        ];
    }
}
