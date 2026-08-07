<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GuideStep>
 */
class GuideStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'locale' => 'en',
            'step_number' => $this->faker->unique()->numberBetween(1, 100),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'photo_path' => $this->faker->optional()->imageUrl(),
            'dua_text' => $this->faker->optional()->sentence(),
            'fiqh_notes' => $this->faker->optional()->paragraph(),
            'checklist' => $this->faker->optional()->randomElements([
                'Make intention',
                'Recite dua',
                'Complete action',
                'Verify completion'
            ], $this->faker->numberBetween(2, 4)),
            'is_published' => true,
        ];
    }

    /**
     * Indicate that the step is unpublished.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
        ]);
    }

    /**
     * Indicate that the step is in Dhivehi.
     */
    public function dhivehi(): static
    {
        return $this->state(fn (array $attributes) => [
            'locale' => 'dv',
            'title' => 'ދިވެހިންނަށްޓަކައިގަނޑުދިނުމަށްޓަކައި',
            'summary' => 'ދިވެހިންނަށްޓަކައިގަނޑުދިނުމަށްޓަކައިގަނޑުދިނުމަށްޓަކައި',
        ]);
    }
}
