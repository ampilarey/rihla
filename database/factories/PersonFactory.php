<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->name();

        return [
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'name' => $name,
            'role' => Person::ROLE_TOUR_LEADER,
            'title' => ['en' => 'Group leader'],
            'bio' => ['en' => $this->faker->sentence()],
            'languages' => ['en' => ['Dhivehi', 'English']],
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function scholar(): static
    {
        return $this->state(fn (): array => [
            'role' => Person::ROLE_SCHOLAR,
            'title' => ['en' => 'Scholar'],
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }
}
