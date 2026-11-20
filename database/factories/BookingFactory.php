<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'departure_id' => Departure::factory(),
            'currency' => 'MVR',
            'seats' => 1,
        ];
    }
}
