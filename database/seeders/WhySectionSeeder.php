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
            // Not "Learn More": a screen reader offers its user the links
            // on a page out of context, and "Learn More" in that list says
            // nothing. Lighthouse fails the homepage on it (§10.1).
            'secondary_cta_text' => 'Read the Umrah guide',
            'secondary_cta_url' => '/guide',
            'is_active' => true,
        ]);

        // §15.3 (Phase 8.3): Rihla is no longer only an Umrah operator, so
        // every claim here has to be as true of a guesthouse customer as of
        // a pilgrim. Generic marketing lines ("Trusted Guides", "Comfort
        // Stays") do not survive that test; four concrete, checkable facts
        // do — and each one is already established elsewhere in this
        // codebase (Seo::REGISTRATION_NUMBER, Seo::FOUNDED, the WhatsApp
        // button every page carries), not invented for this section.
        $featuresEn = [
            [
                'title' => 'Licensed and registered',
                'text' => 'Registered with the Maldives Ministry of Economic Development under REG NO: C11452023 — a real, licensed travel operator, not a marketing page.',
                'icon' => '📋',
                'sort_order' => 0,
                'is_active' => true,
            ],
            [
                'title' => 'A Maldivian company',
                'text' => 'Based in Malé and run by Maldivians, for anyone travelling to or through the Maldives.',
                'icon' => '🇲🇻',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'title' => 'Real people on WhatsApp',
                'text' => 'Message us any time and an actual person on our team replies, not a chatbot.',
                'icon' => '💬',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'title' => 'Since 2023',
                'text' => 'Registered in 2023, and every journey since has been run by the same small team.',
                'icon' => '🕊️',
                'sort_order' => 3,
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
