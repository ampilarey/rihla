<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stays\Models\Property;
use Modules\Stays\Models\PropertyAddon;

/** @extends Factory<PropertyAddon> */
class PropertyAddonFactory extends Factory
{
    protected $model = PropertyAddon::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => ['en' => 'Airport transfer'],
            'description' => ['en' => 'Speedboat from Velana, both ways.'],
            'pricing' => PropertyAddon::PER_STAY,
            'price_minor' => 5000,
            'local_price_minor' => null,
            'is_active' => true,
        ];
    }

    public function perPerson(): static
    {
        return $this->state(fn (): array => ['pricing' => PropertyAddon::PER_PERSON]);
    }
}
