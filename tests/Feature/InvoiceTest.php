<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Invoices\Documents;
use App\Services\Payments\Ledger;
use App\Services\Portal\Gatekeeper;
use App\Support\Access;
use App\Support\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Invoices and receipts — §5.3's outputs.
 *
 * A PDF that downloads is not a PDF that reads: dompdf will happily return
 * a valid file full of boxes, or one missing the half of the page a closure
 * threw on. Every test here pulls the bytes and looks inside them.
 */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function booking(int $totalMinor = 2_850_000): Booking
    {
        $customer = Customer::factory()->create([
            'name' => 'Ibrahim Waheed',
            'phone' => '7712345',
        ]);

        $package = Package::factory()->create(['title' => ['en' => 'Shawwal Umrah']]);

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'package_id' => $package->getKey(),
                'date_start' => now()->addDays(60),
                'date_end' => now()->addDays(74),
            ])->getKey(),
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => $totalMinor,
        ]);

        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create([
                'full_name' => 'Ibrahim Waheed',
            ])->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        return $booking->refresh();
    }

    /**
     * The words actually on the page.
     *
     * A file that downloads is not a file that reads: dompdf will happily
     * return a valid PDF full of boxes, or one missing the half of the page
     * a closure threw on, and `assertStringStartsWith('%PDF')` passes for
     * all of it. So the content streams are inflated and the text operators
     * read out.
     *
     * dompdf writes each glyph run as `[( R i h l a)] TJ` — kerned, one
     * space between every letter — so the spaces are squeezed out before
     * comparing. That makes the result unsuitable for checking spacing, and
     * exactly right for checking that a word is on the page at all.
     */
    private function textOf(string $pdf): string
    {
        $this->assertStringStartsWith('%PDF', $pdf, 'That is not a PDF.');

        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams);

        $content = '';

        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $content .= $inflated === false ? $stream : $inflated;
        }

        preg_match_all('/\((.*?)\)\s*Tj|\[\((.*?)\)\]\s*TJ/s', $content, $runs);

        $text = '';

        foreach (array_filter(array_merge($runs[1], $runs[2])) as $run) {
            $text .= self::decodeRun($run);
        }

        // Un-kern. dompdf writes each glyph with a separator between it and
        // the next, so every space — the real ones included — is dropped,
        // which is why this is only ever used to ask whether a word appears
        // rather than how it is spaced.
        return (string) preg_replace('/\s+/', '', $text);
    }

    /**
     * One glyph run, as characters.
     *
     * dompdf writes runs for an embedded font in UTF-16BE — each character
     * two bytes, with NUL used as the kerning gap — so the raw bytes look
     * like "\0R\0i\0h\0l\0a" and a naive comparison finds nothing. The
     * first version of this test asserted against those bytes and reported
     * that a document containing the phone number did not contain it.
     */
    private static function decodeRun(string $run): string
    {
        if (! str_contains($run, "\0")) {
            return $run;
        }

        return (string) mb_convert_encoding(
            strlen($run) % 2 === 0 ? $run : $run."\0",
            'UTF-8',
            'UTF-16BE',
        );
    }

    private function assertPdfSays(string $pdf, string ...$expected): void
    {
        $text = $this->textOf($pdf);

        foreach ($expected as $word) {
            $this->assertStringContainsString(
                (string) preg_replace('/\s+/', '', $word),
                $text,
                sprintf('The document does not say "%s".', $word),
            );
        }
    }

    // ── The invoice ──────────────────────────────────────────────────────

    public function test_an_invoice_renders_with_the_booking_on_it(): void
    {
        $booking = $this->booking();

        $pdf = app(Documents::class)->invoice($booking)->output();

        $this->assertPdfSays(
            $pdf,
            'Invoice',
            'Rihla Travels',
            (string) $booking->reference,
            'Ibrahim Waheed',
            'Shawwal Umrah',
            'MVR 28,500',
            // The registration number is real — it is on the live site's own
            // footer — so it belongs on the document.
            'C11452023',
        );
    }

    public function test_the_filename_carries_the_reference(): void
    {
        $booking = $this->booking();

        $this->assertSame(
            'invoice-'.strtolower((string) $booking->reference).'.pdf',
            app(Documents::class)->invoiceFilename($booking),
        );
    }

    /** Lines are the record; the total is a cached sum of them. */
    public function test_an_invoice_with_lines_renders(): void
    {
        $booking = $this->booking();

        BookingLine::create([
            'booking_id' => $booking->getKey(),
            'type' => BookingLine::SEAT,
            'description' => 'Quad room, adult',
            'quantity' => 1,
            'unit_amount_minor' => 2_850_000,
            'amount_minor' => 2_850_000,
            'currency' => 'MVR',
        ]);

        $pdf = app(Documents::class)->invoice($booking->refresh())->output();

        $this->assertPdfSays($pdf, 'Quad room, adult', 'MVR 28,500');
    }

    // ── The receipt ──────────────────────────────────────────────────────

    public function test_a_receipt_renders(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'amount_minor' => 1_000_000,
            'payer_name' => 'Aminath Zahira',
        ]);
        app(Ledger::class)->reconcile($payment);

        $pdf = app(Documents::class)->receipt($payment->fresh())->output();

        $this->assertPdfSays($pdf, 'Receipt', 'Aminath Zahira', 'MVR 10,000', (string) $booking->reference);
    }

    public function test_a_refund_note_renders(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create(['payable_type' => Booking::class, 'payable_id' => $booking->getKey(), 'amount_minor' => 1_000_000]);
        app(Ledger::class)->reconcile($payment);
        $refund = app(Ledger::class)->refund($payment, null, 'Changed departure');

        $pdf = app(Documents::class)->receipt($refund)->output();

        // A refund note, not a receipt: they are different documents to the
        // person holding one.
        $this->assertPdfSays($pdf, 'Refund', 'MVR -10,000');
        $this->assertTrue($refund->isRefund());
    }

    /**
     * A Dhivehi document embeds the Thaana font.
     *
     * dompdf falls back silently when a face is missing, and the fallback
     * has no Thaana glyphs.
     */
    public function test_a_dhivehi_invoice_embeds_the_thaana_font(): void
    {
        $this->assertFileExists(public_path('fonts/A_faruma.ttf'), 'The Thaana font is missing.');

        $booking = $this->booking();
        $booking->departure->package->forceFill([
            'title' => ['en' => 'Shawwal Umrah', 'dv' => 'ޝައްވާލް ޢުމްރާ'],
        ])->save();

        app()->setLocale('dv');

        $pdf = app(Documents::class)->invoice($booking->fresh())->output();

        // The Thaana text itself is not asserted here: for an embedded,
        // subsetted font the glyph runs are not Unicode, and the naive
        // decoder in textOf() reads them as question marks. It was checked
        // by hand with a real PDF parser, and what is guarded automatically
        // is the condition that made it fail — see the next test.
        $this->assertStringContainsString('/BaseFont', $pdf);
        $this->assertStringContainsString('AFaruma', $pdf);
    }

    /**
     * **Every PDF that can show Thaana declares a bold face for it.**
     *
     * A_Faruma ships Regular only. With no bold face declared, dompdf
     * resolves `<strong>` and `font-weight: bold` to a *different family* —
     * Helvetica-Bold — which has no Thaana glyphs, so every bold Dhivehi
     * word renders as a row of question marks. It is completely silent: the
     * file downloads, the body text is perfect, and only the headings are
     * ruined.
     *
     * This was live. The Dhivehi Umrah guide's headings were question marks
     * on rihla.mv, under a comment in that same file claiming the font
     * problem had been fixed — the body text had been checked and the
     * headings had not. Found by rendering the PDF and reading it with a
     * real parser, not by any test.
     *
     * A source-level guard rather than a rendering one, for the reason
     * above: the rendered check needs a PDF parser this project does not
     * depend on, so what is asserted is the declaration whose absence
     * causes the defect.
     */
    public function test_every_thaana_pdf_declares_a_bold_face(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views/pdf')) as $file) {
            $source = $file->getContents();

            if (! str_contains($source, 'A_faruma.ttf')) {
                continue;
            }

            // Inside an @font-face block that names the Thaana font, not
            // anywhere in the file. The first version of this matched any
            // `font-weight: bold` and so passed with the @font-face
            // declaration deleted — the guide's own heading rules satisfied
            // it. Found by planting the defect, which is the only way that
            // kind of looseness shows up.
            preg_match_all('/@font-face\s*\{([^}]*)\}/s', $source, $faces);

            $declaresBold = false;

            foreach ($faces[1] as $face) {
                if (str_contains($face, 'A_faruma.ttf') && preg_match('/font-weight:\s*bold/', $face)) {
                    $declaresBold = true;

                    break;
                }
            }

            if (! $declaresBold) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These PDFs load the Thaana font without declaring a bold face.',
                'dompdf then resolves <strong> to Helvetica-Bold, which has no Thaana',
                'glyphs, and every bold Dhivehi word becomes a row of question marks:'],
            $offenders,
        )));
    }

    // ── Nothing is invented ──────────────────────────────────────────────

    /**
     * **No tax line and no terms.**
     *
     * Whether an outbound Umrah package attracts Maldivian GST, at what
     * rate, and whether Rihla is registered for it are questions nobody has
     * answered. The terms document does not exist either. Printing either
     * puts a false statement on a document a customer may hand to an
     * accountant.
     */
    public function test_no_tax_or_terms_are_invented(): void
    {
        // Defined and empty, rather than absent: see config/invoices.php.
        $this->assertSame('', config('invoices.tax.note'));
        $this->assertSame('', config('invoices.terms.note'));

        // Asserted against the rendered page rather than the template. The
        // first version of this read the Blade source and failed on the
        // comment that explains *why* there is no tax line — a test that
        // fires on its own explanation is one that gets deleted.
        $text = $this->textOf(app(Documents::class)->invoice($this->booking())->output());

        $this->assertStringNotContainsString('GST', $text);
        $this->assertStringNotContainsString('Tax', $text);
        $this->assertStringNotContainsString('Terms', $text);
    }

    public function test_a_configured_tax_note_appears(): void
    {
        config(['invoices.tax.note' => 'Prices include 6% GST.']);

        $pdf = app(Documents::class)->invoice($this->booking())->output();

        $this->assertPdfSays($pdf, 'Prices include 6% GST.');
    }

    /** The number on the document is the number on the website. */
    public function test_the_phone_number_comes_from_settings(): void
    {
        Setting::updateOrCreate(['key' => 'whatsapp_number'], ['value' => '9601234567']);

        $pdf = app(Documents::class)->invoice($this->booking())->output();

        // The number was hard-coded in thirteen places once, and four of
        // them disagreed with the admin panel.
        $this->assertPdfSays($pdf, Contact::displayNumber());
    }

    // ── Who may download ─────────────────────────────────────────────────

    private function enterPortal(Booking $booking): void
    {
        $token = app(Gatekeeper::class)->issue($booking);
        $this->get("/en/portal/enter/{$token}")->assertRedirect('/en/portal');
    }

    public function test_a_customer_can_download_their_own_invoice(): void
    {
        $booking = $this->booking();
        $this->enterPortal($booking);

        $this->get('/en/portal/invoice')
            ->assertOk()
            ->assertDownload('invoice-'.strtolower((string) $booking->reference).'.pdf');
    }

    public function test_without_a_portal_session_there_is_no_invoice(): void
    {
        $this->get('/en/portal/invoice')->assertRedirect('/en/portal/locked');
    }

    /**
     * The payment id is a number in a URL until somebody proves it belongs
     * to this booking.
     */
    public function test_a_customer_cannot_download_a_strangers_receipt(): void
    {
        $mine = $this->booking();
        $theirs = $this->booking();

        $strangers = Payment::factory()->create(['payable_type' => Booking::class, 'payable_id' => $theirs->getKey()]);
        app(Ledger::class)->reconcile($strangers);

        $this->enterPortal($mine);

        $this->get('/en/portal/receipt/'.$strangers->getKey())->assertForbidden();
    }

    public function test_no_receipt_for_money_nobody_has_checked(): void
    {
        $booking = $this->booking();
        $claim = Payment::factory()->awaitingReview()->create(['payable_type' => Booking::class, 'payable_id' => $booking->getKey()]);

        $this->enterPortal($booking);

        $this->get('/en/portal/receipt/'.$claim->getKey())->assertNotFound();
    }

    public function test_a_customer_can_download_a_receipt_for_money_that_arrived(): void
    {
        $booking = $this->booking();
        $payment = Payment::factory()->create(['payable_type' => Booking::class, 'payable_id' => $booking->getKey()]);
        app(Ledger::class)->reconcile($payment);

        $this->enterPortal($booking);

        $this->get('/en/portal/receipt/'.$payment->getKey())
            ->assertOk()
            ->assertDownload('receipt-'.strtolower((string) $payment->fresh()->reference).'.pdf');
    }

    // ── Staff ────────────────────────────────────────────────────────────

    public function test_staff_can_download_an_invoice(): void
    {
        $booking = $this->booking();
        $staff = User::factory()->create()->assignRole(Access::BOOKING_STAFF);

        $this->actingAs($staff)
            ->get(route('staff.invoice', ['booking' => $booking]))
            ->assertOk();
    }

    public function test_a_role_that_cannot_read_a_booking_gets_nothing(): void
    {
        $booking = $this->booking();
        $staff = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->actingAs($staff)
            ->get(route('staff.invoice', ['booking' => $booking]))
            ->assertForbidden();
    }

    public function test_a_stranger_off_the_street_gets_nothing(): void
    {
        $booking = $this->booking();

        $this->get(route('staff.invoice', ['booking' => $booking]))->assertRedirect('/login');
    }
}
