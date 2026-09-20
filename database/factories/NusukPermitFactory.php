<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\NusukPermit;
use App\Models\Traveller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NusukPermit>
 */
class NusukPermitFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'traveller_id' => Traveller::factory(),
            'kind' => NusukPermit::UMRAH,
            'attempt' => 1,
        ];
    }

    public function rawdah(): static
    {
        return $this->state(fn (): array => ['kind' => NusukPermit::RAWDAH]);
    }
}
