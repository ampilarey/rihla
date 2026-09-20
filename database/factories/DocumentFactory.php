<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Traveller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'traveller_id' => Traveller::factory(),
            'category' => Document::IDENTITY,
            'type' => Document::PASSPORT,
        ];
    }
}
