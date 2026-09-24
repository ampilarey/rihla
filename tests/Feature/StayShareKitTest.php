<?php

namespace Tests\Feature;

use App\Http\Controllers\StaysController;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Stays\ShareCard;
use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The share kit — §15.4 (Phase 9.5).
 *
 * The owner's stated need, in its most literal form: *"so I can share
 * information for them easily"*. A link dropped into WhatsApp either
 * unfurls into a picture of the guesthouse with its name under it, or it
 * unfurls into the Rihla logo and says nothing — and a one-page PDF is
 * what gets forwarded to whoever is actually paying.
 */
class StayShareKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Services::save(['stays_guesthouses' => Services::ON]);
    }

    private function property(bool $withCover = true): Property
    {
        $property = Property::factory()->create([
            'slug' => 'maafushi-view',
            'island' => 'Maafushi',
            'name' => ['en' => 'Maafushi View'],
            'summary' => ['en' => 'Two minutes from the ferry jetty.'],
            'currency' => 'USD',
        ]);

        RoomType::factory()->create([
            'property_id' => $property->id,
            'name' => ['en' => 'Sea View Double'],
            'base_rate_minor' => 8500,
        ]);

        if ($withCover) {
            // A portrait photograph, which is what a phone actually takes —
            // and the shape the card exists to deal with.
            $path = UploadedFile::fake()
                ->image('cover.jpg', 900, 1600)
                ->store('properties', 'public');

            $property->update(['cover_image' => $path]);
        }

        return $property->fresh();
    }

    // ── The preview card ─────────────────────────────────────────────────

    public function test_the_page_points_its_preview_at_a_card_cut_from_the_cover(): void
    {
        $property = $this->property();

        $html = $this->get('/en/stays/maafushi-view')->assertOk()->getContent();

        $this->assertStringContainsString('stays/maafushi-view/card.png?v=', $html);
        $this->assertStringContainsString('og:title" content="Maafushi View"', $html);
        $this->assertStringContainsString('Two minutes from the ferry jetty.', $html);
    }

    /**
     * 1200×630 exactly. Every scraper renders that ratio whole; handed a
     * 3:4 photograph, WhatsApp and Facebook each crop it their own way —
     * usually through the middle of the building — and Twitter letterboxes
     * it.
     */
    public function test_the_card_is_the_one_shape_every_scraper_renders_whole(): void
    {
        $this->property();

        $response = $this->get('/en/stays/maafushi-view/card.png')->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));

        $image = imagecreatefromstring($response->getContent());

        $this->assertNotFalse($image);
        $this->assertSame(ShareCard::WIDTH, imagesx($image));
        $this->assertSame(ShareCard::HEIGHT, imagesy($image));
    }

    /**
     * The URL carries a content hash, and that is not decoration.
     *
     * Every scraper caches by URL for weeks and none re-check on a
     * schedule worth relying on. A stable URL whose bytes change is the
     * defect `AGENTS.md` records for the service worker: a path that
     * outlives its contents serves last year's artwork for ever.
     */
    public function test_replacing_the_cover_changes_the_preview_url(): void
    {
        $property = $this->property();

        $before = $this->versionIn($this->get('/en/stays/maafushi-view')->getContent());

        $property->update([
            'cover_image' => UploadedFile::fake()
                ->image('new-cover.jpg', 1600, 900)
                ->store('properties', 'public'),
        ]);

        $after = $this->versionIn($this->get('/en/stays/maafushi-view')->getContent());

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after, 'A new cover must produce a URL no scraper has cached.');
    }

    /**
     * No cover: the brand image, not a broken one.
     *
     * A generic preview is a smaller problem than a link that unfurls into
     * nothing, and far smaller than a 500 on a page somebody is sharing.
     */
    public function test_a_property_with_no_cover_falls_back_to_the_brand_image(): void
    {
        $this->property(withCover: false);

        $html = $this->get('/en/stays/maafushi-view')->assertOk()->getContent();

        $this->assertStringContainsString('rihla-social.png', $html);
        $this->assertStringNotContainsString('card.png', $html);
    }

    public function test_the_card_route_falls_back_rather_than_failing(): void
    {
        $this->property(withCover: false);

        $this->get('/en/stays/maafushi-view/card.png')
            ->assertRedirect()
            ->assertRedirectContains('rihla-social.png');
    }

    public function test_an_unpublished_property_has_no_card(): void
    {
        $this->property()->update(['is_published' => false]);

        $this->get('/en/stays/maafushi-view/card.png')->assertNotFound();
    }

    /** The card is generated once and kept, not rebuilt on every scrape. */
    public function test_the_card_is_cached_after_the_first_request(): void
    {
        $this->property();

        $this->get('/en/stays/maafushi-view/card.png')->assertOk();

        $cached = Storage::disk('public')->files('share-cards');

        $this->assertCount(1, $cached);
        $this->assertStringContainsString('maafushi-view', $cached[0]);
    }

    // ── The fact sheet ───────────────────────────────────────────────────

    public function test_the_page_offers_a_one_page_summary(): void
    {
        $this->property();

        $this->get('/en/stays/maafushi-view')
            ->assertOk()
            ->assertSee('Download a one-page summary')
            ->assertSee('stays/maafushi-view/sheet.pdf');
    }

    public function test_the_fact_sheet_downloads_as_a_pdf(): void
    {
        $this->property();

        $response = $this->get('/en/stays/maafushi-view/sheet.pdf')->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'maafushi-view-en.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /** One sheet per language this can honestly be printed in. */
    public function test_each_printable_language_gets_its_own_sheet(): void
    {
        $this->property();

        foreach (StaysController::SHEET_LOCALES as $locale) {
            $response = $this->get("/{$locale}/stays/maafushi-view/sheet.pdf")->assertOk();

            $this->assertStringContainsString(
                "maafushi-view-{$locale}.pdf",
                (string) $response->headers->get('Content-Disposition'),
                "The {$locale} fact sheet is not named for its language.",
            );
        }
    }

    /**
     * **No Arabic sheet, and this is the assertion that keeps it that way.**
     *
     * dompdf reverses an RTL run but applies no contextual shaping. A
     * rendered Arabic sheet extracts as 21 base letters and 0 presentation
     * forms — on the page, every letter in its isolated form, joined to
     * nothing. To an Arabic reader that is visibly broken, on a document
     * meant to be forwarded to a customer, and nobody re-reads a PDF they
     * already sent.
     *
     * Measured with `pdftotext` against a real render, not assumed. If
     * somebody adds a shaper, this test is the thing to delete — and
     * deleting it deliberately is the point.
     */
    public function test_no_arabic_sheet_is_offered_because_it_would_print_unjoined(): void
    {
        $this->property();

        $this->assertNotContains('ar', StaysController::SHEET_LOCALES);

        $this->get('/ar/stays/maafushi-view/sheet.pdf')->assertNotFound();

        // And the page does not dangle a link to it.
        $this->get('/ar/stays/maafushi-view')
            ->assertOk()
            ->assertDontSee('sheet.pdf');
    }

    /** Thaana does not join, so Dhivehi prints correctly and is offered. */
    public function test_the_dhivehi_sheet_is_offered_because_thaana_does_not_join(): void
    {
        $this->property();

        $this->assertContains('dv', StaysController::SHEET_LOCALES);

        $this->get('/dv/stays/maafushi-view')
            ->assertOk()
            ->assertSee('sheet.pdf');
    }

    public function test_an_unpublished_property_has_no_fact_sheet(): void
    {
        $this->property()->update(['is_published' => false]);

        $this->get('/en/stays/maafushi-view/sheet.pdf')->assertNotFound();
    }

    public function test_a_fact_sheet_is_unreachable_when_the_service_is_off(): void
    {
        $this->property();

        Services::save(['stays_guesthouses' => Services::OFF]);

        $this->get('/en/stays/maafushi-view/sheet.pdf')->assertNotFound();
    }

    // ── The routes that could have swallowed each other ──────────────────

    /**
     * `/stays/{property}` is declared last, but `card.png` and `sheet.pdf`
     * sit *under* a property slug — so they have to be declared before it
     * or the catch-all reads "maafushi-view/card.png" as a slug and 404s.
     */
    public function test_the_share_kit_routes_are_not_swallowed_by_the_property_route(): void
    {
        $this->property();

        $this->get('/en/stays/maafushi-view')->assertOk();
        $this->get('/en/stays/maafushi-view/card.png')->assertOk();
        $this->get('/en/stays/maafushi-view/sheet.pdf')->assertOk();
    }

    private function versionIn(string $html): ?string
    {
        preg_match('/card\.png\?v=([a-f0-9]+)/', $html, $matches);

        return $matches[1] ?? null;
    }
}
