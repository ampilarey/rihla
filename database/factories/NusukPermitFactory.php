<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\NusukPermit;
use Modules\Umrah\Models\Traveller;

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
