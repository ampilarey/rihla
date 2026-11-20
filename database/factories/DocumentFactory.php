<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Umrah\Models\Document;
use Modules\Umrah\Models\Traveller;

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
