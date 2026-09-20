<?php

namespace Database\Factories;

use App\Models\CrmTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrmTask> */
class CrmTaskFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subject' => 'Placeholder task for a test fixture',
            'due_on' => now()->addDays(3)->toDateString(),
        ];
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn (): array => ['due_on' => $date]);
    }

    public function done(): static
    {
        return $this->state(fn (): array => ['done_at' => now()]);
    }
}
