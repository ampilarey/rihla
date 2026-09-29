<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PropertyPhoto> */
class PropertyPhotoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'disk' => 'public',
            'path' => 'properties/photos/'.Str::random(12).'.jpg',
            'caption' => ['en' => 'The view from the terrace'],
            'sort_order' => 0,
        ];
    }
}
