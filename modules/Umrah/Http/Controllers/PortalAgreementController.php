<?php

namespace Modules\Umrah\Http\Controllers;

use App\Exceptions\DeskRefusal;
use App\Http\Controllers\Controller;
use App\Support\CompanyDetails;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Umrah\Models\Agreement;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Services\Invoices\Documents;
use Modules\Umrah\Services\Portal\Agreements;
use Symfony\Component\HttpFoundation\Response;

/**
 * The booking agreement in the portal (companies plan, Phase 3h, part 3):
 * read it, sign it on screen, or send a scan of a signed paper copy. None
 * of it exists until the company has written its terms.
 */
class PortalAgreementController extends Controller
{
    public function __construct(private readonly Agreements $agreements) {}

    public function show(Request $request): View
    {
        abort_unless(Agreements::offered(), 404);

        $booking = $this->booking($request);

        return view('portal.agreement', [
            'booking' => $booking,
            'agreement' => $this->agreements->for($booking),
            'terms' => (string) CompanyDetails::terms(),
        ]);
    }

    public function sign(Request $request): RedirectResponse
    {
        abort_unless(Agreements::offered(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'signature' => ['required', 'string'],
            'agree' => ['accepted'],
        ]);

        try {
            $this->agreements->signOnScreen($this->booking($request), $data['name'], $data['signature'], $request->ip(), $request->userAgent());
        } catch (DeskRefusal $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return redirect()->route('portal.agreement')->with('portal_notice', __('messages.Thank you. Your agreement is signed.'));
    }

    public function upload(Request $request): RedirectResponse
    {
        abort_unless(Agreements::offered(), 404);

        $data = $request->validate([
            'file' => [
                'required', 'file',
                'mimetypes:'.implode(',', (array) config('documents.mime_types')),
                'max:'.(int) config('documents.max_kilobytes'),
            ],
        ]);

        try {
            $this->agreements->recordPaper($this->booking($request), $data['file']);
        } catch (DeskRefusal $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('portal.agreement')->with('portal_notice', __('messages.Thank you. We have your signed copy.'));
    }

    /** The agreement as signed, to keep. */
    public function pdf(Request $request): Response
    {
        $booking = $this->booking($request);
        $agreement = $this->agreements->for($booking);
        abort_unless($agreement->isSigned(), 404);

        return self::document($booking, $agreement)->download('agreement-'.strtolower((string) $booking->reference).'.pdf');
    }

    public static function document(Booking $booking, Agreement $agreement): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('pdf.agreement', [
            'booking' => $booking->loadMissing(['customer', 'departure.package']),
            'agreement' => $agreement,
            'issuer' => app(Documents::class)->issuer(),
        ]);
    }

    private function booking(Request $request): Booking
    {
        /** @var Booking */
        return $request->attributes->get('portal_booking');
    }
}
