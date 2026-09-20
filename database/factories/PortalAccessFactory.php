<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\PortalAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortalAccess>
 */
class PortalAccessFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'token_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
