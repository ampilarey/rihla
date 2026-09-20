<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'method' => Payment::BANK_TRANSFER,
            'currency' => 'MVR',
            // Whole rufiyaa in laari [R-7].
            'amount_minor' => 2_850_000,
        ];
    }

    public function cash(): static
    {
        return $this->state(fn (): array => ['method' => Payment::CASH]);
    }

    public function awaitingReview(): static
    {
        return $this->state(fn (): array => ['status' => Payment::AWAITING_REVIEW]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => Payment::SUCCEEDED,
            'paid_at' => now(),
            'reviewed_at' => now(),
        ]);
    }
}
