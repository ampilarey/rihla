<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Umrah\Models\Enquiry;
use Modules\Umrah\Models\Quotation;

/** @extends Factory<Quotation> */
class QuotationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'enquiry_id' => Enquiry::factory(),
            'party_size' => 2,
            'currency' => 'MVR',
            // Integer minor units, never a float — [R-7].
            'total_minor' => 5_700_000,
            'includes' => 'Placeholder inclusions for a test fixture.',
            'valid_until' => now()->addDays(14)->toDateString(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => Quotation::SENT,
            'sent_at' => now(),
        ]);
    }

    public function expiringOn(string $date): static
    {
        return $this->state(fn (): array => ['valid_until' => $date]);
    }
}
