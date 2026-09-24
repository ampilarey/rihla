<?php

namespace Database\Factories;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $island = $this->faker->randomElement([
            'Maafushi', 'Fulidhoo', 'Thulusdhoo', 'Dhigurah', 'Ukulhas',
        ]);

        return [
            'name' => $island.' '.$this->faker->randomElement(['Retreat', 'Inn', 'Stay', 'Beach House']),
            'island' => $island,
            'contact_name' => $this->faker->name(),
            'phone' => '+960'.$this->faker->numerify('#######'),
            'whatsapp' => '+960'.$this->faker->numerify('#######'),
            'email' => $this->faker->unique()->safeEmail(),
            'pricing_model' => Partner::NET_RATE,
            'green_tax_mode' => Partner::GREEN_TAX_AT_PROPERTY,
            'is_active' => true,
        ];
    }

    public function commissionBased(int $pct = 15): static
    {
        return $this->state(fn (): array => [
            'pricing_model' => Partner::COMMISSION,
            'commission_pct' => $pct,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
