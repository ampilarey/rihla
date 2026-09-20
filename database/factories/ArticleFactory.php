<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = $this->faker->sentence(5);

        return [
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'title' => ['en' => $title],
            'excerpt' => ['en' => $this->faker->sentence()],
            'body' => ['en' => $this->faker->paragraphs(3, true)],
            'published_at' => now()->subDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['published_at' => null]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => ['published_at' => now()->addWeek()]);
    }
}
