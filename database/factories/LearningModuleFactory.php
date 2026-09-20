<?php

namespace Database\Factories;

use App\Models\LearningModule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LearningModule>
 *
 * Deliberately neutral placeholder text. This factory must never carry
 * religious instruction: a fixture that reads like a real lesson on a rite
 * is one that ends up copied into a seeder, and AGENTS.md records what that
 * has already cost this codebase.
 */
class LearningModuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = 'Module '.Str::random(6);

        return [
            'slug' => Str::slug($title),
            'title' => ['en' => $title],
            'summary' => ['en' => 'Placeholder summary for a test fixture.'],
            'body' => ['en' => 'Placeholder body for a test fixture.'],
            'minutes' => 5,
            'days_before_departure' => 30,
        ];
    }

    public function dueDaysBefore(int $days): static
    {
        return $this->state(fn (): array => ['days_before_departure' => $days]);
    }

    /**
     * On the site, without going through the editorial gate.
     *
     * For fixtures that need a live module and are not testing the gate
     * itself. The real route is a scholar approving it and somebody
     * publishing it; this sets the two columns that route ends at, and no
     * test of sign-off may use it.
     */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => LearningModule::PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }
}
