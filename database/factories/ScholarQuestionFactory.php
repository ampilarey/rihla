<?php

namespace Database\Factories;

use App\Models\ScholarQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScholarQuestion>
 *
 * Deliberately meaningless. A fixture that reads like a real question —
 * still less a real answer — is one that ends up on a screen somebody
 * believes.
 */
class ScholarQuestionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'body' => 'Placeholder question for a test fixture.',
            'locale' => 'en',
            'may_publish' => false,
        ];
    }

    public function mayBePublished(): static
    {
        return $this->state(fn (): array => ['may_publish' => true]);
    }
}
