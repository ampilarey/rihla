<?php

namespace Modules\Transport\Http\Controllers;

use App\Exceptions\DeskRefusal;
use App\Http\Controllers\Controller;
use App\Models\Island;
use App\Support\CompanyDetails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Transport\Models\TransportRequest;
use Modules\Transport\Services\TransportRequests;

/**
 * Asking for cargo or a charter — docs/TRANSPORT_PLAN.md (T4). A request
 * is an enquiry, so it is taken while the service is coming soon as well
 * as on. The asker follows it on a page found by a random key.
 */
class TransportRequestController extends Controller
{
    public function create(Request $request): View
    {
        return view('transport.request', [
            'islands' => Island::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'kind' => $request->query('kind') === TransportRequest::CHARTER ? TransportRequest::CHARTER : TransportRequest::CARGO,
        ]);
    }

    public function store(Request $request, TransportRequests $requests): RedirectResponse
    {
        // A field a person never sees; a bot fills it in.
        if (filled($request->input('website'))) {
            return redirect()->route('transport.index');
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(TransportRequest::KINDS))],
            'origin_island_id' => ['required', 'integer', Rule::exists('islands', 'id')->where('is_active', true)],
            'destination_island_id' => ['required', 'integer', 'different:origin_island_id', Rule::exists('islands', 'id')->where('is_active', true)],
            'wanted_on' => ['required', 'date', 'after_or_equal:today', 'before:+1 year'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'passengers' => ['nullable', 'required_if:kind,charter', 'integer', 'min:1', 'max:500'],
            'cargo_description' => ['nullable', 'required_if:kind,cargo', 'string', 'max:2000'],
            'cargo_weight_kg' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $made = $requests->submit([
                'kind' => $data['kind'],
                'origin_island_id' => (int) $data['origin_island_id'],
                'destination_island_id' => (int) $data['destination_island_id'],
                'wanted_on' => $data['wanted_on'],
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'passengers' => isset($data['passengers']) ? (int) $data['passengers'] : null,
                'cargo_description' => $data['cargo_description'] ?? null,
                'cargo_weight_kg' => isset($data['cargo_weight_kg']) ? (int) $data['cargo_weight_kg'] : null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (DeskRefusal $refusal) {
            return back()->withInput()->withErrors(['kind' => $refusal->getMessage()]);
        }

        return redirect()->to(self::statusUrl($made));
    }

    public function show(string $key): View
    {
        $made = TransportRequest::query()->where('status_key', $key)->with(['origin', 'destination', 'events'])->firstOrFail();
        $bank = CompanyDetails::bank();
        $account = $bank['accounts'][$made->currency] ?? null;

        return view('transport.request-status', [
            'made' => $made,
            'transfer' => blank($account) ? null : ['bank' => $bank['bank'], 'account_name' => $bank['account_name'], 'account' => (string) $account],
        ]);
    }

    public static function statusUrl(TransportRequest $made): string
    {
        return route('transport.requests.show', ['locale' => app()->getLocale(), 'key' => $made->status_key]);
    }
}
