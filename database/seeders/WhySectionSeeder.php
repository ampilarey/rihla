<?php

namespace Database\Seeders;

use App\Models\WhyFeature;
use App\Models\WhySection;
use Illuminate\Database\Seeder;

class WhySectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // The one why-section, seeded in English. Its Dhivehi half is typed by
        // a person in the admin panel or left blank, and blank falls back.
        $whySectionEn = WhySection::create([
            'title' => 'Why Choose Rihla',
            'subtitle' => 'Discover the unique advantages that make us your perfect travel partner',
            'primary_cta_text' => 'Start Your Journey',
            'primary_cta_url' => '/trips',
            'secondary_cta_text' => 'Learn More',
            'secondary_cta_url' => '/guide',
            'is_active' => true,
        ]);

        // Create English features
        $featuresEn = [
            [
                'title' => 'Trusted Guides',
                'text' => 'Our experienced local guides know every hidden gem and secret spot, ensuring you get the most authentic experience.',
                'icon' => '🧭',
                'sort_order' => 0,
                'is_active' => true,
            ],
            [
                'title' => 'Comfort Stays',
                'text' => 'Carefully selected accommodations that blend comfort with local charm, making every night a restful experience.',
                'icon' => '🏨',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'title' => 'Clear Pricing',
                'text' => 'Transparent pricing with no hidden fees. What you see is what you pay, making budgeting simple and stress-free.',
                'icon' => '💰',
                'sort_order' => 2,
                'is_active' => true,
            ],
        ];

        foreach ($featuresEn as $featureData) {
            $featureData['why_section_id'] = $whySectionEn->id;
            WhyFeature::create($featureData);
        }

        // Dhivehi is deliberately not seeded.
        //
        // What used to be here was machine-generated: the three feature titles
        // shared a 19-character suffix, and two of the three bodies shared 72
        // of their 77 characters, where the English copy for the same three
        // points shares two. On the homepage, which made it the fabricated
        // Dhivehi a visitor was most likely to see.
        //
        // HomeController falls back to the English section for a locale with
        // none, so /dv keeps the block. A real translation entered in the
        // admin panel wins the moment it exists.

    }
}
