<?php

namespace App\Services\Invoices;

use App\Models\Booking;
use App\Models\Payment;
use App\Support\Contact;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * An invoice for a booking, and a receipt for a payment — §5.3's outputs.
 *
 * ## Generated on demand, never stored
 *
 * There is no `invoices` table and no saved file. An invoice is a rendering
 * of the booking as it stands, and a stored PDF is a second copy of the
 * truth that starts drifting the moment a line changes. What *is* immutable
 * — what was agreed, what was paid, who decided it — is already in
 * `booking_lines`, `payments` and `payment_transactions`, each of which is
 * append-only or audited.
 *
 * The one thing that argues for storing them is a legal requirement to keep
 * a numbered, unalterable series. Nobody has said there is one. When
 * somebody does, this is where it changes.
 *
 * ## Nothing on the page is invented
 *
 * No tax line, because nobody has said whether an outbound Umrah package is
 * subject to Maldivian GST or at what rate. No terms, because the terms
 * document does not exist yet. No bank account unless one is configured.
 * A plausible-looking invented line on a document a customer keeps — and may
 * hand to an accountant — is worse than a missing one.
 *
 * ## Dhivehi renders, or it does not go out
 *
 * dompdf falls back silently to a font with no Thaana glyphs, which is how
 * the Umrah guide once downloaded as a page of boxes. The font is loaded
 * explicitly from `public/fonts/A_faruma.ttf`, the same one the guide now
 * uses.
 */
final class Documents
{
    public function invoice(Booking $booking): PdfDocument
    {
        return Pdf::loadView('pdf.invoice', [
            'booking' => $booking->load(['customer', 'departure.package', 'lines', 'travellers.traveller']),
            'payments' => $booking->payments()->succeeded()->get(),
            'issuer' => $this->issuer(),
            'locale' => app()->getLocale(),
        ]);
    }

    public function receipt(Payment $payment): PdfDocument
    {
        return Pdf::loadView('pdf.receipt', [
            'payment' => $payment->load(['payable.customer', 'payable.departure.package']),
            'booking' => $payment->booking(),
            'issuer' => $this->issuer(),
            'locale' => app()->getLocale(),
        ]);
    }

    public function invoiceFilename(Booking $booking): string
    {
        return 'invoice-'.strtolower((string) $booking->reference).'.pdf';
    }

    public function receiptFilename(Payment $payment): string
    {
        return 'receipt-'.strtolower((string) $payment->reference).'.pdf';
    }

    /**
     * The issuing business, from configuration and settings.
     *
     * The phone number is read through Contact rather than copied, so it
     * cannot disagree with the number on the website — which is exactly
     * what happened when it was written out by hand in thirteen places.
     *
     * @return array<string, string|null>
     */
    private function issuer(): array
    {
        return [
            'name' => (string) config('invoices.issuer.name'),
            'registration' => config('invoices.issuer.registration'),
            'address' => config('invoices.issuer.address'),
            'email' => config('invoices.issuer.email'),
            'phone' => Contact::displayNumber(),
        ];
    }
}
