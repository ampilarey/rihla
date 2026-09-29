<?php

namespace Database\Factories;

use App\Models\Review;
use App\Models\Stay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A review of a completed stay. Public by default — `waiting()` for one
 * still inside its publishing delay, `hidden()` for one Rihla hid.
 *
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory()->state(['status' => Stay::COMPLETED]),
            'property_id' => fn (array $attributes): int => (int) Stay::findOrFail($attributes['stay_id'])->property_id,
            'partner_id' => fn (array $attributes): int => (int) Stay::findOrFail($attributes['stay_id'])->property->partner_id,
            'customer_id' => fn (array $attributes): int => (int) Stay::findOrFail($attributes['stay_id'])->customer_id,
            'rating' => 5,
            'body' => 'Lovely family, spotless room.',
            'locale' => 'en',
            'submitted_at' => now()->subDays(3),
            'published_at' => now()->subDay(),
        ];
    }

    public function waiting(): static
    {
        return $this->state(fn (): array => ['published_at' => now()->addDay()]);
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['hidden_at' => now(), 'hidden_reason' => 'Names a member of staff.']);
    }
}
