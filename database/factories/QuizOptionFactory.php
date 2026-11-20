<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Content\Models\QuizOption;
use Modules\Content\Models\QuizQuestion;

/** @extends Factory<QuizOption> */
class QuizOptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'quiz_question_id' => QuizQuestion::factory(),
            'text' => ['en' => 'Placeholder answer for a test fixture.'],
            'is_correct' => false,
            'sort_order' => 0,
        ];
    }

    public function correct(): static
    {
        return $this->state(fn (): array => ['is_correct' => true]);
    }
}
