<?php

namespace Database\Factories;

use App\Models\Departure;
use App\Models\DepartureCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DepartureCost> */
class DepartureCostFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'departure_id' => Departure::factory(),
            'category' => DepartureCost::HOTEL,
            'supplier' => 'Placeholder supplier',
            'currency' => 'MVR',
            'amount_minor' => 1_000_000,
            'is_per_person' => false,
            'status' => DepartureCost::ESTIMATED,
        ];
    }

    public function perPerson(): static
    {
        return $this->state(fn (): array => ['is_per_person' => true]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function inCurrency(string $currency, int $minor): static
    {
        return $this->state(fn (): array => ['currency' => $currency, 'amount_minor' => $minor]);
    }
}
