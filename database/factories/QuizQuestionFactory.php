<?php

namespace Database\Factories;

use App\Models\LearningModule;
use App\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizQuestion>
 *
 * Structurally shaped and deliberately meaningless. A fixture that states a
 * real question about a rite — with a real right answer — is one somebody
 * copies onto the live site without the scholar this feature exists to
 * require.
 */
class QuizQuestionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learning_module_id' => LearningModule::factory(),
            'prompt' => ['en' => 'Placeholder question for a test fixture?'],
            'explanation' => ['en' => 'Placeholder explanation for a test fixture.'],
            'sort_order' => 0,
        ];
    }
}
