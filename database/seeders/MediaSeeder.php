<?php

namespace Database\Seeders;

use App\Models\Media;
use App\Models\Trip;
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
            $this->command?->warn(static::class.' skipped: demo data is not seeded in production.');

            return;
        }
        // Get the past trip
        $pastTrip = Trip::where('status', 'past')->first();

        if ($pastTrip) {
            // Add a YouTube video
            Media::create([
                'trip_id' => $pastTrip->id,
                'type' => 'video',
                'title' => 'Cultural Heritage Tour Highlights',
                'caption' => 'Watch our amazing journey through Malé\'s cultural sites',
                'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', // Replace with actual video
                'thumb_path' => null,
                'sort_order' => 1,
                'is_published' => true,
            ]);

            // Add another video
            Media::create([
                'trip_id' => $pastTrip->id,
                'type' => 'video',
                'title' => 'Local Market Experience',
                'caption' => 'Exploring the vibrant local markets of Malé',
                'video_url' => 'https://www.youtube.com/watch?v=9bZkp7q19f0', // Replace with actual video
                'thumb_path' => null,
                'sort_order' => 2,
                'is_published' => true,
            ]);
        }

        // Add some standalone media (not tied to specific trips)
        Media::create([
            'trip_id' => null,
            'type' => 'photo',
            'title' => 'Maldives Sunset',
            'caption' => 'Beautiful sunset over the Indian Ocean',
            'file_path' => 'media/sunset.jpg',
            'thumb_path' => 'media/sunset-thumb.jpg',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        Media::create([
            'trip_id' => null,
            'type' => 'photo',
            'title' => 'Crystal Clear Waters',
            'caption' => 'The pristine waters of the Maldives',
            'file_path' => 'media/waters.jpg',
            'thumb_path' => 'media/waters-thumb.jpg',
            'sort_order' => 2,
            'is_published' => true,
        ]);
    }
}
