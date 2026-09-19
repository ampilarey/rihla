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
        // What this used to hold, and why none of it is here any more:
        //
        //   "Maldives Island Hopping Adventure"  — white sandy beaches, water sports
        //   "Luxury Resort Experience"           — overwater villas, "perfect for
        //                                          honeymooners and luxury travelers"
        //   "Cultural Heritage Tour"             — traditional markets, local crafts
        //
        // All three were live on test.rihla.mv, public and under Rihla's
        // branding, each on its own indexed URL: an Umrah operator selling
        // honeymoon resort stays. The guard above was added after demo content
        // reached production and did exactly this, but it only governs where
        // the data runs, not what it says — and nothing had read it since.
        //
        // The figures below are illustrative, not quotes. They exist so the
        // homepage, the trips list and a trip page have something shaped like
        // real content to lay out.
        $trips = [
            [
                'title' => 'Ramadan Umrah — 14 Nights',
                'slug' => 'ramadan-umrah-14-nights',
                'date_start' => now()->subDays(5),
                'date_end' => now()->addDays(9),
                'location' => 'Makkah & Madinah',
                'summary' => 'Fourteen nights across the two holy cities, with a Maldivian group leader throughout.',
                'details' => 'A guided Umrah group departing Velana International for Jeddah, with eight nights in '
                    .'Makkah and six in Madinah. Hotels are within walking distance of the Haram. A Dhivehi-speaking '
                    .'group leader travels with the party from departure to return, and the fiqh of each rite is '
                    .'covered in a briefing before departure.',
                'price_from_mvr' => 28500,
                'status' => 'current',
            ],
            [
                'title' => 'Shawwal Umrah — 10 Nights',
                'slug' => 'shawwal-umrah-10-nights',
                'date_start' => now()->addDays(34),
                'date_end' => now()->addDays(44),
                'location' => 'Makkah & Madinah',
                'summary' => 'A shorter group for those who cannot take a full fortnight away.',
                'details' => 'Six nights in Makkah and four in Madinah, with the same group-leader arrangement and '
                    .'the same walking-distance hotels. Visa and permit processing is handled before departure.',
                'price_from_mvr' => 22000,
                'status' => 'upcoming',
            ],
            [
                'title' => 'Rabi al-Awwal Umrah — 12 Nights',
                'slug' => 'rabi-al-awwal-umrah-12-nights',
                'date_start' => now()->subDays(64),
                'date_end' => now()->subDays(52),
                'location' => 'Makkah & Madinah',
                'summary' => 'A completed departure, kept here so past groups stay visible.',
                'details' => 'Twelve nights across both cities with ziyarah in Madinah. Listed as past so the trips '
                    .'page has something in each of its three tabs.',
                'price_from_mvr' => 26000,
                'status' => 'past',
            ],
        ];

        foreach ($trips as $trip) {
            Trip::create($trip + ['is_published' => true]);
        }
    }
}
