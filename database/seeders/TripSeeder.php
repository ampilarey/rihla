<?php

namespace Database\Seeders;

use App\Models\Trip;
use Illuminate\Database\Seeder;

class TripSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Demo content. It reached production once and advertised resort
        // holidays on an Umrah site; never let it run there again.
        if (app()->isProduction()) {
            $this->command->warn(static::class.' skipped: demo data is not seeded in production.');

            return;
        }
        // Current Trip
        Trip::create([
            'title' => 'Maldives Island Hopping Adventure',
            'slug' => 'maldives-island-hopping-adventure',
            'date_start' => now()->subDays(5),
            'date_end' => now()->addDays(10),
            'location' => 'Malé Atoll, Maldives',
            'summary' => 'Experience the beauty of multiple islands in the Maldives with our current adventure.',
            'details' => 'Join us for an unforgettable journey through the pristine waters and white sandy beaches of the Maldives. Visit local islands, enjoy water sports, and immerse yourself in the local culture.',
            'price_from_mvr' => 15000,
            'status' => 'current',
            'is_published' => true,
        ]);

        // Upcoming Trip
        Trip::create([
            'title' => 'Luxury Resort Experience',
            'slug' => 'luxury-resort-experience',
            'date_start' => now()->addDays(30),
            'date_end' => now()->addDays(37),
            'location' => 'Baa Atoll, Maldives',
            'summary' => 'Indulge in luxury at one of the most exclusive resorts in the Maldives.',
            'details' => 'Experience world-class service, overwater villas, private beaches, and gourmet dining. Perfect for honeymooners and luxury travelers seeking the ultimate Maldives experience.',
            'price_from_mvr' => 25000,
            'status' => 'upcoming',
            'is_published' => true,
        ]);

        // Past Trip
        Trip::create([
            'title' => 'Cultural Heritage Tour',
            'slug' => 'cultural-heritage-tour',
            'date_start' => now()->subDays(60),
            'date_end' => now()->subDays(53),
            'location' => 'Malé City, Maldives',
            'summary' => 'Explore the rich cultural heritage of Malé and surrounding islands.',
            'details' => 'Discover historical mosques, traditional markets, and local crafts. Learn about Maldivian history, cuisine, and traditions while meeting friendly locals.',
            'price_from_mvr' => 8000,
            'status' => 'past',
            'is_published' => true,
        ]);
    }
}
