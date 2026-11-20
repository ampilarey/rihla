<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Traveller;
use Modules\Umrah\Models\VisaApplication;

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
