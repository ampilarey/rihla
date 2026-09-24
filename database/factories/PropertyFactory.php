<?php

namespace Database\Factories;

use App\Models\Partner;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $island = $this->faker->randomElement([
            'Maafushi', 'Fulidhoo', 'Thulusdhoo', 'Dhigurah', 'Ukulhas',
        ]);

        $name = $island.' '.$this->faker->randomElement(['View', 'Sands', 'Breeze', 'Lagoon']);

        return [
            'partner_id' => Partner::factory(),
            'type' => Property::GUESTHOUSE,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'island' => $island,
            'name' => ['en' => $name],
            'summary' => ['en' => $this->faker->sentence()],
            'description' => ['en' => $this->faker->paragraph()],
            'house_rules' => ['en' => 'No alcohol. Modest dress on the village beach.'],
            'check_in_instructions' => ['en' => 'The ferry jetty is a five-minute walk.'],
            // A list per language, never null — the column holds a list or
            // nothing, the same rule PackageFactory records.
            'amenities' => ['en' => ['Air conditioning', 'Wi-Fi', 'Breakfast']],
            'check_in_time' => '14:00',
            'check_out_time' => '11:00',
            'instant_book' => false,
            'min_nights' => 2,
            'currency' => 'USD',
            'is_published' => true,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }

    public function instantBook(): static
    {
        return $this->state(fn (): array => ['instant_book' => true]);
    }

    /** A Malé room, which Phase 11 sells on this same engine. */
    public function rental(): static
    {
        return $this->state(fn (): array => [
            'type' => Property::RENTAL,
            'currency' => 'MVR',
            'instant_book' => true,
            'min_nights' => 1,
        ]);
    }
}
