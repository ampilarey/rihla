<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\Invoices\Documents;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same invoice and receipt, for staff.
 *
 * A separate controller from the portal's, because the question "may this
 * person see this booking?" has a completely different answer for a member
 * of staff and for a customer holding a link. Merging them would mean one
 * method with two authorisation paths, and the day somebody edits it, one
 * of those paths is the one they did not have in mind.
 *
 * Not signed: a member of staff opening a booking they may already read
 * through the admin panel is not the threat a WhatsApp link is. The policy
 * is the gate.
 */
class StaffInvoiceController extends Controller
{
    public function __construct(private readonly Documents $documents) {}

    public function invoice(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        return $this->documents->invoice($booking)
            ->download($this->documents->invoiceFilename($booking));
    }

    public function receipt(Payment $payment): Response
    {
        $this->authorize('view', $payment);

        // A receipt is for money that arrived, whoever is asking.
        abort_unless($payment->status === Payment::SUCCEEDED, 404);

        return $this->documents->receipt($payment)
            ->download($this->documents->receiptFilename($payment));
    }
}
