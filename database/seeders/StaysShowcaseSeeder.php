<?php

namespace Database\Seeders;

use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Support\Services;
use Illuminate\Database\Seeder;

/**
 * One guesthouse, so the Lighthouse job has a real Stays page to audit —
 * §15.4 (Phase 9.4).
 *
 * **Deliberately not in `DatabaseSeeder`.** It turns a service *on*, and a
 * seeder that quietly makes a line of business live is the exact failure
 * the registry exists to prevent — §15.2 decision 6 keeps Guesthouses
 * `coming_soon` until the owner confirms the travel-agency licence, and
 * that is a decision for a person, not a migration step. The Lighthouse job
 * runs this by name.
 *
 * Refuses in production for the same reason the demo seeders do, and says
 * so rather than failing quietly.
 *
 * Auditing an empty page proves nothing, which is why this exists at all:
 * without it `/en/stays` would 404 in CI and Lighthouse would happily score
 * the error page 100 for accessibility — a gate reporting green about
 * something else.
 */
class StaysShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('StaysShowcaseSeeder skipped in production: it switches a service on.');

            return;
        }

        $partner = Partner::firstOrCreate(
            ['name' => 'Maafushi Retreat'],
            [
                'island' => 'Maafushi',
                'contact_name' => 'Demo Partner',
                'green_tax_mode' => Partner::GREEN_TAX_AT_PROPERTY,
                'pricing_model' => Partner::NET_RATE,
                'is_active' => true,
            ],
        );

        $property = Property::firstOrCreate(
            ['slug' => 'maafushi-view'],
            [
                'partner_id' => $partner->getKey(),
                'type' => Property::GUESTHOUSE,
                'island' => 'Maafushi',
                'name' => ['en' => 'Maafushi View'],
                'summary' => ['en' => 'A small guesthouse on the village side, two minutes from the ferry jetty.'],
                'description' => ['en' => "Eight rooms, run by the family who built it.\n\nBreakfast is included and the bikini beach is a five-minute walk."],
                'house_rules' => ['en' => 'Modest dress on the village beach. No alcohol on the island.'],
                'check_in_instructions' => ['en' => 'Ring on arrival at the harbour and somebody will meet you.'],
                'amenities' => ['en' => ['Air conditioning', 'Wi-Fi', 'Breakfast included', 'Airport transfer arranged']],
                'check_in_time' => '14:00',
                'check_out_time' => '11:00',
                'min_nights' => 2,
                'currency' => 'USD',
                'is_published' => true,
            ],
        );

        RoomType::firstOrCreate(
            ['property_id' => $property->getKey(), 'sort_order' => 0],
            [
                'name' => ['en' => 'Sea View Double'],
                'description' => ['en' => 'A double bed, a balcony and the lagoon in front of it.'],
                'sleeps' => 2,
                'beds' => '1 double',
                'size_m2' => 22,
                'amenities' => ['en' => ['Balcony', 'Private bathroom']],
                'quantity' => 3,
                'base_rate_minor' => 8500,
            ],
        );

        Services::save([
            'stays_guesthouses' => Services::ON,
            'stays_island_holidays' => Services::COMING_SOON,
            'stays_rooms' => Services::COMING_SOON,
        ]);

        $this->command->info('Seeded one guesthouse and switched Stays on — for auditing only.');
    }
}
