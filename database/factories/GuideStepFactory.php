<?php

namespace Database\Factories;

use App\Models\GuideStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuideStep>
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
            'step_number' => $this->faker->unique()->numberBetween(1, 100),
            'title' => $this->faker->sentence(3),
            'summary' => $this->faker->paragraph(),
            'details' => $this->faker->optional()->paragraphs(3, true),
            'image_path' => $this->faker->optional()->imageUrl(),
            'dua_text' => $this->faker->optional()->sentence(),
            'reference_text' => $this->faker->optional()->sentence(),
            // A JSON array, one note per school of thought — matching the
            // model's cast, the seeder and what the admin panel submits.
            'fiqh_notes' => $this->faker->optional()->randomElements([
                'Obligatory in all four schools',
                'Hanafi: recommended before departure',
                'Shafi\'i: may be combined with the following step',
            ], 2) ?? [],
            'checklist' => $this->faker->optional()->randomElements([
                'Make intention',
                'Recite dua',
                'Complete action',
                'Verify completion',
            ], $this->faker->numberBetween(2, 4)) ?? [],
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
     * Give the step a Dhivehi translation beside its English one.
     *
     * A step is one record in both languages now, so this adds to the row
     * rather than making a second one.
     */
    public function dhivehi(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => [
                'en' => $attributes['title'],
                'dv' => 'ދިވެހިންނަށްޓަކައިގަނޑުދިނުމަށްޓަކައި',
            ],
            'summary' => [
                'en' => $attributes['summary'],
                'dv' => 'ދިވެހިންނަށްޓަކައިގަނޑުދިނުމަށްޓަކައިގަނޑުދިނުމަށްޓަކައި',
            ],
        ]);
    }
}
