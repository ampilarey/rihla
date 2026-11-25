<?php

namespace Modules\Travel\Http\Controllers;

use App\Exceptions\DeskRefusal;
use App\Http\Controllers\Controller;
use App\Support\CompanyDetails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Modules\Travel\Models\TravelDocument;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Models\TravelVisaApplication;
use Modules\Travel\Models\VisaProduct;
use Modules\Travel\Services\TravelDocuments;
use Modules\Travel\Services\TravelRequests;
use Modules\Travel\Services\VisaDesk;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * "Plan my trip" — docs/TRAVEL_AGENCY_PLAN.md (A1). A request is an
 * enquiry, so it is taken while the service is coming soon as well as on.
 * The customer follows it, and chooses an option, on a page found by a
 * random key.
 */
class TravelController extends Controller
{
    public function index(): View
    {
        return view('travel.index', [
            'visas' => VisaProduct::query()->where('is_active', true)->orderBy('country')->orderBy('visa_type')->get(),
        ]);
    }

    public function store(Request $request, TravelRequests $requests): RedirectResponse
    {
        // A field a person never sees; a bot fills it in.
        if (filled($request->input('website'))) {
            return redirect()->route('travel.index');
        }

        $data = $request->validate([
            'wants' => ['required', 'array', 'min:1'],
            'wants.*' => ['in:flight,hotel,visa'],
            'origin' => ['nullable', 'string', 'max:80'],
            'destination' => ['required', 'string', 'max:120'],
            'depart_on' => ['required', 'date', 'after_or_equal:today', 'before:+2 years'],
            'return_on' => ['nullable', 'date', 'after_or_equal:depart_on'],
            'adults' => ['required', 'integer', 'min:1', 'max:50'],
            'children' => ['nullable', 'integer', 'min:0', 'max:50'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $wants = (array) $data['wants'];

        try {
            $made = $requests->submit([
                'wants_flight' => in_array('flight', $wants, true),
                'wants_hotel' => in_array('hotel', $wants, true),
                'wants_visa' => in_array('visa', $wants, true),
                'origin' => $data['origin'] ?? null,
                'destination' => $data['destination'],
                'depart_on' => $data['depart_on'],
                'return_on' => $data['return_on'] ?? null,
                'adults' => (int) $data['adults'],
                'children' => (int) ($data['children'] ?? 0),
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (DeskRefusal $refusal) {
            return back()->withInput()->withErrors(['wants' => $refusal->getMessage()]);
        }

        return redirect()->to(self::statusUrl($made));
    }

    public function show(string $key): View
    {
        $made = $this->find($key);
        $made->load(['options', 'events', 'acceptedOption', 'documents', 'visaApplications.product']);
        $bank = CompanyDetails::bank();
        $account = $bank['accounts'][$made->currency] ?? null;

        return view('travel.request', [
            'made' => $made,
            'transfer' => blank($account) ? null : ['bank' => $bank['bank'], 'account_name' => $bank['account_name'], 'account' => (string) $account],
        ]);
    }

    public function accept(Request $request, string $key, TravelRequests $requests): RedirectResponse
    {
        $made = $this->find($key);
        $data = $request->validate(['option' => ['required', 'integer']]);

        try {
            $requests->accept($made, (int) $data['option']);
        } catch (DeskRefusal $refusal) {
            return redirect()->to(self::statusUrl($made))->withErrors(['option' => $refusal->getMessage()]);
        }

        return redirect()->to(self::statusUrl($made))->with('status', __('messages.Thank you. Your choice is saved; pay as shown below.'));
    }

    /** One document on a visa checklist, sent by the customer (A3). */
    public function visaDocument(Request $request, string $key, int $application, int $item, VisaDesk $desk): RedirectResponse
    {
        $made = $this->find($key);
        /** @var TravelVisaApplication $visa */
        $visa = $made->visaApplications()->whereKey($application)->firstOrFail();

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) config('documents.max_kilobytes', 8192)],
        ]);

        try {
            /** @var UploadedFile $file */
            $file = $request->file('file');
            $desk->receive($visa, $item, $file);
        } catch (DeskRefusal $refusal) {
            return redirect()->to(self::statusUrl($made))->withErrors(['file' => $refusal->getMessage()]);
        }

        return redirect()->to(self::statusUrl($made))->with('status', __('messages.Thank you, we have it.'));
    }

    /** A ticket, voucher or visa, for whoever holds the trip page's key. */
    public function document(string $key, int $document, TravelDocuments $documents): Response
    {
        $made = $this->find($key);
        /** @var TravelDocument $file */
        $file = $made->documents()->whereKey($document)->firstOrFail();

        try {
            $download = $documents->download($file, null, 'customer');
        } catch (DeskRefusal) {
            abort(404);
        }

        return response($download['contents'], 200, [
            'Content-Type' => $download['mime'],
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $download['name'], 'document'),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function statusUrl(TravelRequest $made): string
    {
        return route('travel.requests.show', ['locale' => app()->getLocale(), 'key' => $made->status_key]);
    }

    private function find(string $key): TravelRequest
    {
        return TravelRequest::query()->where('status_key', $key)->firstOrFail();
    }
}
