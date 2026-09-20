<?php

namespace Database\Factories;

use App\Models\LocationMisconception;
use App\Models\ZiyarahLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocationMisconception>
 *
 * The belief and the correction are structurally shaped and deliberately
 * meaningless. A fixture that states a real misconception — or a real
 * correction to one — is one somebody copies onto the live site without the
 * scholar this feature exists to require.
 */
class LocationMisconceptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ziyarah_location_id' => ZiyarahLocation::factory(),
            'belief' => ['en' => 'Placeholder belief for a test fixture.'],
            'correction' => ['en' => 'Placeholder correction for a test fixture.'],
            'sort_order' => 0,
        ];
    }
}
