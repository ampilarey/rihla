<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Text that has to survive a right-to-left page.
 *
 * Every defect guarded here was found by rendering the Dhivehi page and
 * looking at it. None of them fails a status code, none changes a single
 * byte of the logical text, and none is visible in English — which is
 * exactly why they reach production.
 */
class BidiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A date with a translated month may not be printed directly.
     *
     * Thaana letters carry the Unicode bidi class AL, and rule W2 retypes a
     * European number following an AL character as an *Arabic* number. The
     * year after a Dhivehi month therefore stops behaving like Latin digits,
     * rule N1 drags the neutral spaces and dashes into the right-to-left run,
     * and "19 ނޮވެންބަރު 2026" renders as "19 2026 ނޮވެންބަރު" — inside an
     * element marked dir="ltr", because base direction was never the problem.
     *
     * x-local-date isolates the month in a <bdi>, which is the fix. This
     * stops the next view reintroducing the defect by doing the obvious
     * thing.
     */
    public function test_no_view_prints_a_translated_month_without_isolating_it(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), 'local-date.blade.php')) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                // A format string containing M or F asks Carbon for a month
                // name; j, d, Y and H are safe because digits are not AL.
                if (preg_match("/translatedFormat\(\s*'[^']*[MF][^']*'/", $line)) {
                    $offenders[] = sprintf(
                        '%s:%d', str_replace(resource_path('views').'/', '', $file->getPathname()), $number + 1,
                    );
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Views print a translated month name directly. Use <x-local-date> —',
                'an unisolated month reorders the whole line on a Dhivehi page:'],
            $offenders,
        )));
    }

    /** And the component actually does isolate it. */
    public function test_the_rendered_dhivehi_page_isolates_the_month(): void
    {
        $package = Package::factory()->create(['slug' => 'bidi-check', 'title' => ['en' => 'Bidi Check']]);
        $departure = Departure::factory()->withSeats(10)->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(74),
        ]);
        PriceTier::create([
            'departure_id' => $departure->getKey(), 'occupancy' => 'quad', 'amount_minor' => 2_850_000,
        ]);

        $html = $this->get('/dv/packages/bidi-check/book')->assertOk()->getContent();

        $this->assertStringContainsString('<bdi>', $html,
            'The month must be isolated, or the year jumps in front of it.');
    }

    /**
     * An English label on a Dhivehi page inherits the page direction, so its
     * trailing "?" or ":" — both bidi-neutral — moves to the front, and the
     * form asks "?How many travellers".
     *
     * Untranslated strings are the normal case here rather than an oversight:
     * Dhivehi is being removed rather than trusted, and a string with no
     * Thaana falls back to English by design.
     */
    public function test_booking_form_labels_declare_their_direction(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views/booking')) as $file) {
            $html = $file->getContents();

            foreach (['label', 'legend'] as $tag) {
                foreach (self::elementsOf($tag, $html) as [$attributes, $content]) {
                    // Only elements whose own text is a translated string. A
                    // <label> wrapping a radio and a card of markup is a
                    // layout container, and forcing its direction would flip
                    // the card rather than fix a sentence.
                    if (! str_contains($content, "__('messages.") || str_contains($content, '<input')) {
                        continue;
                    }

                    if (! str_contains($attributes, 'dir=')) {
                        $offenders[] = $file->getFilename().': <'.$tag.'> '.trim(substr($content, 0, 48));
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Form labels need dir="auto", or their punctuation jumps to the front in Dhivehi:'],
            $offenders,
        )));
    }

    /**
     * The footer's sentences, which are on every page in both languages.
     *
     * Found by rendering the Dhivehi portal and reading the bottom of the
     * page: "© 2026 Rihla Travels. All rights reserved." came out as
     * ".Rihla Travels. All rights reserved 2026 ©", and the company
     * description ended ".and Madinah". Both are rule N1 again — neutral
     * punctuation at the end of a Latin run inside an RTL paragraph is
     * dragged to the visual front.
     *
     * Every paragraph in the footer whose text is Latin, or falls back to
     * Latin because no Dhivehi translation exists, has to declare a
     * direction. This is the third time this class of defect has been found
     * by looking at a page rather than by a test.
     */
    public function test_footer_sentences_declare_their_direction(): void
    {
        $html = File::get(resource_path('views/layouts/app.blade.php'));

        $footer = substr($html, (int) strpos($html, '<footer'));

        $offenders = [];

        foreach (self::elementsOf('p', $footer) as [$attributes, $content]) {
            $text = trim($content);

            if ($text === '' || str_contains($content, '<')) {
                continue;
            }

            if (! str_contains($attributes, 'dir=')) {
                $offenders[] = trim(substr(preg_replace('/\s+/', ' ', $text) ?? '', 0, 60));
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Footer paragraphs need a direction. Without one, an English sentence',
                'inside the Dhivehi footer has its final full stop dragged to the front:'],
            $offenders,
        )));
    }

    /**
     * @return list<array{string, string}> attributes and inner content
     */
    private static function elementsOf(string $tag, string $html): array
    {
        preg_match_all('/<'.$tag.'([^>]*)>(.*?)<\/'.$tag.'>/s', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => [$m[1], $m[2]], $matches);
    }
}
