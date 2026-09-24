<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stay>
 *
 * The property and the room type have to agree, so the default builds the
 * room and takes the property from it. Passing a `room_type_id` without a
 * matching `property_id` would make a stay against a building whose
 * calendar it does not appear on — which every availability query would
 * then miss.
 */
class StayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $checkIn = now()->addMonth()->startOfDay();
        $nights = $this->faker->numberBetween(2, 5);

        return [
            'customer_id' => Customer::factory(),
            'room_type_id' => RoomType::factory(),
            'property_id' => fn (array $attributes): int => (int) RoomType::findOrFail($attributes['room_type_id'])->property_id,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays($nights)->toDateString(),
            'nights' => $nights,
            'adults' => 2,
            'children' => 0,
            'currency' => 'USD',
            'status' => Stay::REQUESTED,
            'requested_at' => now(),
        ];
    }

    /** Somebody else's dates: the partner said yes and the clock is running. */
    public function held(): static
    {
        return $this->state(fn (): array => [
            'status' => Stay::HELD,
            'partner_confirmed_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => Stay::CONFIRMED,
            'partner_confirmed_at' => now()->subHour(),
            'confirmed_at' => now(),
            'expires_at' => null,
        ]);
    }

    /** A hold whose clock ran out while nobody was looking. */
    public function lapsed(): static
    {
        return $this->state(fn (): array => [
            'status' => Stay::HELD,
            'partner_confirmed_at' => now()->subDays(2),
            'expires_at' => now()->subHour(),
        ]);
    }

    /**
     * @param  CarbonInterface|string  $checkIn
     */
    public function forNights($checkIn, int $nights): static
    {
        $from = CarbonImmutable::parse($checkIn);

        return $this->state(fn (): array => [
            'check_in' => $from->toDateString(),
            'check_out' => $from->addDays($nights)->toDateString(),
            'nights' => $nights,
        ]);
    }
}
