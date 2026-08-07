<?php

namespace Database\Seeders;

use App\Models\WhySection;
use App\Models\WhyFeature;
use Illuminate\Database\Seeder;

class WhySectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create English why section
        $whySectionEn = WhySection::create([
            'locale' => 'en',
            'title' => 'Why Choose Rihla',
            'subtitle' => 'Discover the unique advantages that make us your perfect travel partner',
            'primary_cta_text' => 'Start Your Journey',
            'primary_cta_url' => '/trips',
            'secondary_cta_text' => 'Learn More',
            'secondary_cta_url' => '/about',
            'is_active' => true,
        ]);

        // Create Dhivehi why section
        $whySectionDv = WhySection::create([
            'locale' => 'dv',
            'title' => 'ރިހްލައަށް އަންނަވާނަންވާކަންތައްވަނީއެވެ؟',
            'subtitle' => 'ތިޔަބޭފުޅުންނަށްޓަކައި ތިމަންމަގައިގެވިގެންވާ އަސަރުވެރިކަންތައްވަނީއެވެ',
            'primary_cta_text' => 'ދަތުރުންނަށްޓަކައިވާލާ',
            'primary_cta_url' => '/trips',
            'secondary_cta_text' => 'އިތުރަށްދަންނަވާލާ',
            'secondary_cta_url' => '/about',
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

        // Create Dhivehi features
        $featuresDv = [
            [
                'title' => 'ވިސްވަރަށްޓަކައިވާލުންތައް',
                'text' => 'ތިމަންމަގައިގެވިގެންވާ މަޝްހޫރުތައްވަނީއެވެއިން ކޮންމެވެސްކަމަށްޓަކައިވާލުންތައްވަނީއެވެ',
                'icon' => '🧭',
                'sort_order' => 0,
                'is_active' => true,
            ],
            [
                'title' => 'ރައްޓަށްޓަކައިވާލުންތައް',
                'text' => 'ރައްޓަށްޓަކައިވާލުންތައްވަނީއެވެއިން ކޮންމެވެސްކަމަށްޓަކައިވާލުންތައްވަނީއެވެ',
                'icon' => '🏨',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'title' => 'ސަފުވަށްޓަކައިވާލުންތައް',
                'text' => 'ސަފުވަށްޓަކައިވާލުންތައްވަނީއެވެއިން ކޮންމެވެސްކަމަށްޓަކައިވާލުންތައްވަނީއެވެ',
                'icon' => '💰',
                'sort_order' => 2,
                'is_active' => true,
            ],
        ];

        foreach ($featuresDv as $featureData) {
            $featureData['why_section_id'] = $whySectionDv->id;
            WhyFeature::create($featureData);
        }
    }
}
