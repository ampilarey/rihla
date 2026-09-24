<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\Invoices\Documents;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoices and receipts, for the customer whose booking it is.
 *
 * ## Reached through the portal session, never by an identifier
 *
 * Both methods take the booking from the portal session — the same rule as
 * every other portal route. A booking reference in the path would let
 * anybody who guessed one download a stranger's invoice, complete with
 * their name and phone number.
 *
 * A receipt names a payment, and that *is* checked against the session's
 * booking: the id is a number in a URL until somebody proves it belongs
 * here.
 *
 * Staff download the same documents from Filament, where the policy already
 * decides who may see a booking.
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly Documents $documents) {}

    public function invoice(Request $request): Response
    {
        $booking = $this->booking($request);

        return $this->documents->invoice($booking)
            ->download($this->documents->invoiceFilename($booking));
    }

    public function receipt(Request $request, Payment $payment): Response
    {
        $booking = $this->booking($request);

        // The payment must be on *this* booking. Without this the id is
        // just a number in a URL and a customer could walk the range.
        abort_unless($payment->bookingKey() === $booking->getKey(), 403);

        // A receipt is for money that arrived. Issuing one for a claim
        // nobody has checked would hand a customer a document saying their
        // payment was received before anybody looked at it.
        abort_unless($payment->status === Payment::SUCCEEDED, 404);

        return $this->documents->receipt($payment)
            ->download($this->documents->receiptFilename($payment));
    }

    /** Put on the request by PortalSession, never read from the URL. */
    private function booking(Request $request): Booking
    {
        /** @var Booking */
        return $request->attributes->get('portal_booking');
    }
}
