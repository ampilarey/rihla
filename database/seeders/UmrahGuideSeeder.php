<?php

namespace Database\Seeders;

use App\Models\GuideStep;
use Illuminate\Database\Seeder;

class UmrahGuideSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedEnglishSteps();

        // Dhivehi steps are deliberately not seeded.
        //
        // The ones that used to be here were machine-generated filler: all ten
        // carried the same du'a and the same reference_text, where the English
        // ten carry ten real du'as and citations. On an Umrah guide that is not
        // a cosmetic defect — a pilgrim reading the supplication for each rite
        // was given the same meaningless sentence every time.
        //
        // They are not replaced with a paraphrase. Religious instruction needs
        // a translator and a scholar, not a seeder. Until one exists the guide
        // falls back to English, which is correct (see
        // PageController::guideStepsFor).
    }

    private function seedEnglishSteps(): void
    {
        $steps = [
            [
                'step_number' => 1,
                'locale' => 'en',
                'title' => 'Intention (Niyyah)',
                'summary' => 'Make a sincere intention in your heart to perform Umrah for the sake of Allah. The intention should be made before entering the state of Ihram.',
                'checklist' => ['Make sincere intention', 'Recite intention in heart', 'Focus on purpose'],
                'dua_text' => 'Allahumma inni uridu al-umrata fa yassirha li wa taqabbalha minni',
                'reference_text' => 'Based on authentic hadith about the importance of intention in worship.',
                'fiqh_notes' => ['Intention is obligatory in all schools of thought', 'Must be made before entering Ihram state'],
                'is_published' => true,
            ],
            [
                'step_number' => 2,
                'locale' => 'en',
                'title' => 'Ihram & Talbiyah',
                'summary' => 'Enter the sacred state of Ihram by wearing the prescribed clothing and reciting the Talbiyah. This marks the beginning of your sacred journey.',
                'checklist' => ['Wear Ihram clothing', 'Recite Talbiyah', 'Avoid prohibited actions'],
                'dua_text' => 'Labbaik Allahumma labbaik, Labbaik la shareeka laka labbaik, Innal hamda wan-ni\'mata laka wal mulk',
                'reference_text' => 'Quran 2:196 and authentic hadith collections.',
                'fiqh_notes' => ['Ihram clothing varies by gender', 'Talbiyah should be recited frequently'],
                'is_published' => true,
            ],
            [
                'step_number' => 3,
                'locale' => 'en',
                'title' => 'Entering Masjid al-Haram',
                'summary' => 'Enter the Grand Mosque with your right foot, reciting the appropriate supplications. Show respect and reverence for this sacred place.',
                'checklist' => ['Enter with right foot', 'Recite entrance dua', 'Maintain reverence'],
                'dua_text' => 'Bismillahi wassalaatu wassalaamu ala rasoolillah, Allahumma aftah li abwaaba rahmatik',
                'reference_text' => 'Authentic hadith about entering mosques.',
                'fiqh_notes' => ['Enter with right foot is sunnah', 'Maintain proper etiquette'],
                'is_published' => true,
            ],
            [
                'step_number' => 4,
                'locale' => 'en',
                'title' => 'Tawaf (7 circuits)',
                'summary' => 'Perform seven complete circuits around the Kaaba, starting from the Black Stone. Each circuit should be done with devotion and focus.',
                'checklist' => ['Start from Black Stone', 'Complete 7 circuits', 'Maintain focus'],
                'dua_text' => 'Bismillahi wallahu akbar, Allahumma imanan bika wa tasdeeqan bi kitaabik',
                'reference_text' => 'Quran 22:29 and authentic hadith about Tawaf.',
                'fiqh_notes' => ['Must complete all 7 circuits', 'Direction is counter-clockwise'],
                'is_published' => true,
            ],
            [
                'step_number' => 5,
                'locale' => 'en',
                'title' => 'Pray 2 Rak\'ah at Maqam Ibrahim (if possible)',
                'summary' => 'After completing Tawaf, pray two rak\'ah at Maqam Ibrahim if space permits. This is a highly recommended act of worship.',
                'checklist' => ['Find space if available', 'Pray 2 rak\'ah', 'Recite recommended surahs'],
                'dua_text' => 'Recite Al-Kafirun in first rak\'ah and Al-Ikhlas in second rak\'ah',
                'reference_text' => 'Authentic hadith about praying at Maqam Ibrahim.',
                'fiqh_notes' => ['Highly recommended but not obligatory', 'Can pray elsewhere if crowded'],
                'is_published' => true,
            ],
            [
                'step_number' => 6,
                'locale' => 'en',
                'title' => 'Drink Zamzam',
                'summary' => 'Drink from the blessed water of Zamzam while standing and facing the Kaaba. This water has special spiritual significance.',
                'checklist' => ['Face Kaaba', 'Drink while standing', 'Make dua'],
                'dua_text' => 'Allahumma inni as\'aluka ilman naafi\'an wa rizqan waasi\'an wa shifaa\'an min kulli daa\'in',
                'reference_text' => 'Reference text about the virtues of Zamzam water.',
                'fiqh_notes' => ['Drinking while standing is sunnah', 'Make dua before drinking'],
                'is_published' => true,
            ],
            [
                'step_number' => 7,
                'locale' => 'en',
                'title' => 'Sa\'i between Safa and Marwah (7 times)',
                'summary' => 'Walk seven times between the hills of Safa and Marwah, commemorating Hajar\'s search for water. This represents patience and trust in Allah.',
                'checklist' => ['Start from Safa', 'Complete 7 rounds', 'Recite recommended dua'],
                'dua_text' => 'Inna as-safa wal marwata min sha\'a\'irillah, fa man hajja al-bayta awi\'tamara',
                'reference_text' => 'Quran 2:158 and authentic hadith about Sa\'i.',
                'fiqh_notes' => ['Must complete all 7 rounds', 'Walking is obligatory, running is sunnah'],
                'is_published' => true,
            ],
            [
                'step_number' => 8,
                'locale' => 'en',
                'title' => 'Halq/Taqsir (shave/trim)',
                'summary' => 'Complete your Umrah by either shaving your head completely (Halq) or trimming your hair (Taqsir). This symbolizes the end of the sacred state.',
                'checklist' => ['Choose Halq or Taqsir', 'Complete the act', 'Exit Ihram state'],
                'dua_text' => 'Alhamdulillah alladhi sallama laka wa qadha laka',
                'reference_text' => 'Quran 48:27 and authentic hadith about completing Umrah.',
                'fiqh_notes' => ['Halq is preferred for men', 'Women should only trim, not shave'],
                'is_published' => true,
            ],
            [
                'step_number' => 9,
                'locale' => 'en',
                'title' => 'Leave Ihram',
                'summary' => 'After completing all rituals, you may leave the state of Ihram. Normal activities and clothing restrictions are now lifted.',
                'checklist' => ['Complete all rituals', 'Remove Ihram clothing', 'Return to normal state'],
                'dua_text' => 'Allahumma inni as\'aluka min fadlik wa rahmatik',
                'reference_text' => 'Based on authentic hadith about completing Umrah.',
                'fiqh_notes' => ['All restrictions are now lifted', 'Can wear normal clothing'],
                'is_published' => true,
            ],
            [
                'step_number' => 10,
                'locale' => 'en',
                'title' => 'Du\'a & Etiquette Guide',
                'summary' => 'Throughout your Umrah journey, maintain proper etiquette, make sincere supplications, and remember the spiritual significance of each act.',
                'checklist' => ['Maintain good manners', 'Make sincere dua', 'Show gratitude'],
                'dua_text' => 'Rabbana taqabbal minna innaka antas samee\'ul aleem',
                'reference_text' => 'Quran 2:127 and general Islamic etiquette guidelines.',
                'fiqh_notes' => ['Good manners are obligatory', 'Dua should be sincere and humble'],
                'is_published' => true,
            ],
        ];

        foreach ($steps as $step) {
            GuideStep::create($step);
        }
    }
}
