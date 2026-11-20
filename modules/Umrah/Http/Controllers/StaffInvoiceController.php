<?php

namespace Modules\Umrah\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\ExcursionBooking;
use Modules\Umrah\Services\Invoices\Documents;
use Modules\Umrah\Services\Portal\Agreements;
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

    /** The booking agreement as signed (Phase 3h, part 3). */
    public function agreement(Booking $booking, Agreements $agreements): Response
    {
        $this->authorize('view', $booking);

        $agreement = $agreements->for($booking);
        abort_unless($agreement->isSigned(), 404);

        return PortalAgreementController::document($booking, $agreement)->download('agreement-'.strtolower((string) $booking->reference).'.pdf');
    }

    /** An additional trip's own invoice (companies plan, Phase 3e). */
    public function excursion(ExcursionBooking $excursionBooking): Response
    {
        $this->authorize('view', $excursionBooking);

        return $this->documents->excursionInvoice($excursionBooking)
            ->download('invoice-'.strtolower((string) $excursionBooking->reference).'.pdf');
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
