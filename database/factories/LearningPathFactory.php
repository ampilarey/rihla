<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Content\Models\LearningPath;

/** @extends Factory<LearningPath> */
class LearningPathFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Path '.Str::random(6);

        return [
            'slug' => Str::slug($name),
            'name' => ['en' => $name],
            'summary' => ['en' => 'Placeholder summary for a test fixture.'],
            'audience' => LearningPath::BEGINNER,
            'is_published' => true,
            'sort_order' => 0,
        ];
    }
}
