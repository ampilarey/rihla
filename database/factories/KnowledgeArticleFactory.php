<?php

namespace Database\Factories;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeArticle>
 *
 * Deliberately neutral placeholder text. This factory must never carry
 * religious content: a fixture that looks like a real narration is one that
 * ends up copied into a seeder, and AGENTS.md records what that has already
 * cost this codebase.
 */
class KnowledgeArticleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = 'Article '.Str::random(6);

        return [
            'slug' => Str::slug($title),
            'title' => ['en' => $title],
            'summary' => ['en' => 'Placeholder summary for a test fixture.'],
            'body' => ['en' => 'Placeholder body for a test fixture.'],
            'category' => KnowledgeArticle::PLACE,
        ];
    }
}
