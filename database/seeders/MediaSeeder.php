<?php

namespace Database\Seeders;

use App\Models\Media;
use Illuminate\Database\Seeder;

class MediaSeeder extends Seeder
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
        //   "Cultural Heritage Tour Highlights"  ->  youtube.com/watch?v=dQw4w9WgXcQ
        //   "Local Market Experience"            ->  youtube.com/watch?v=9bZkp7q19f0
        //
        // Both carried a "Replace with actual video" comment and neither was.
        // They were live on test.rihla.mv, on the public internet, under
        // Rihla's branding: an Umrah operator's gallery playing Rick Astley
        // and Gangnam Style. Alongside them sat "Maldives Sunset" and
        // "Crystal Clear Waters" — resort-holiday stock copy on a pilgrimage
        // site, which is the exact thing the guard above was added to stop,
        // except the guard only stopped it reaching production.
        //
        // No demo video is seeded now. Every YouTube id is somebody's real
        // video, so there is no such thing as a placeholder one; a video demo
        // needs a real Rihla upload.
        //
        // The photographs carry no file. That is deliberate rather than
        // broken: <x-stored-image> renders the mark on cream when a file is
        // missing, so the gallery layout is exercised and a visitor sees
        // "no picture yet" rather than a torn-page icon.
        $subjects = [
            ['Ihram at Masjid Aisha', 'Pilgrims entering the state of ihram before Umrah.'],
            ['Arriving in Madinah', 'The group arriving for the Madinah leg of the journey.'],
            ['Group briefing in Malé', 'Pre-departure briefing before leaving for Jeddah.'],
        ];

        foreach ($subjects as $index => [$title, $caption]) {
            Media::create([
                'trip_id' => null,
                'type' => 'photo',
                'title' => $title,
                'caption' => $caption,
                'file_path' => null,
                'thumb_path' => null,
                'sort_order' => $index + 1,
                'is_published' => true,
            ]);
        }
    }
}
