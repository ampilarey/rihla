<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = $this->faker->randomElement([
            'Ramadan Umrah', 'Shawwal Umrah', 'Family Umrah', 'Economy Umrah',
        ]).' — '.$this->faker->numberBetween(7, 21).' Nights';

        return [
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'title' => ['en' => $title],
            'summary' => ['en' => $this->faker->sentence()],
            'details' => ['en' => $this->faker->paragraph()],
            // A list per language, never null: the column holds a list or
            // nothing, and optional() would hand back a null it cannot mean.
            'inclusions' => ['en' => ['Return flights', 'Visa processing', 'Hotel accommodation']],
            'exclusions' => ['en' => ['Personal expenses', 'Travel insurance']],
            'nights' => $this->faker->numberBetween(7, 21),
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }
}
