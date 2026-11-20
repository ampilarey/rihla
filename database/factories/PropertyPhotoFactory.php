<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Stays\Models\Property;
use Modules\Stays\Models\PropertyPhoto;

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
