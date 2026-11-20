<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Content\Models\ArticleReference;
use Modules\Content\Models\KnowledgeArticle;

/**
 * @extends Factory<ArticleReference>
 *
 * The citations here are structurally shaped and deliberately not real:
 * a fixture that reads as a genuine reference is one somebody copies.
 */
class ArticleReferenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'referenceable_type' => KnowledgeArticle::class,
            'referenceable_id' => KnowledgeArticle::factory(),
            'kind' => ArticleReference::QURAN,
            'citation' => 'Placeholder citation for a test fixture',
        ];
    }

    public function hadith(string $grading = ArticleReference::SAHIH): static
    {
        return $this->state(fn (): array => [
            'kind' => ArticleReference::HADITH,
            'grading' => $grading,
        ]);
    }
}
