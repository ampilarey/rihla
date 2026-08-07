<?php

namespace Database\Seeders;

use App\Models\GuideStep;
use Illuminate\Database\Seeder;

class GuideStepSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $steps = [
            [
                'step_number' => 1,
                'title' => 'Niyyah (Intention)',
                'description' => 'Begin your Umrah journey with a sincere intention in your heart. Recite the Talbiyah: "Labbayk Allahumma labbayk, labbayka laa shareeka laka labbayk, innal hamda wan-ni\'mata laka wal mulk, laa shareeka laka labbayk."',
                'photo_path' => 'guide/step1.jpg',
                'is_published' => true,
            ],
            [
                'step_number' => 2,
                'title' => 'Ihram',
                'description' => 'Wear the sacred Ihram clothes - two white unsewn pieces of cloth for men, and modest clothing for women. Enter the state of Ihram at the designated Miqat point.',
                'photo_path' => 'guide/step2.jpg',
                'is_published' => true,
            ],
            [
                'step_number' => 3,
                'title' => 'Tawaf',
                'description' => 'Circumambulate the Kaaba seven times in a counter-clockwise direction, starting from the Black Stone. Each circuit begins and ends at the Black Stone, with prayers and supplications throughout.',
                'photo_path' => 'guide/step3.jpg',
                'is_published' => true,
            ],
            [
                'step_number' => 4,
                'title' => 'Sa\'i',
                'description' => 'Walk seven times between the hills of Safa and Marwa, commemorating Hajar\'s search for water for her son Ismail. This ritual represents faith, perseverance, and trust in Allah.',
                'photo_path' => 'guide/step4.jpg',
                'is_published' => true,
            ],
            [
                'step_number' => 5,
                'title' => 'Halq/Taqsir',
                'description' => 'Complete your Umrah by either shaving your head completely (Halq) or trimming a portion of your hair (Taqsir). This symbolizes the completion of the sacred journey and renewal of faith.',
                'photo_path' => 'guide/step5.jpg',
                'is_published' => true,
            ],
        ];

        foreach ($steps as $step) {
            GuideStep::create($step);
        }
    }
}
