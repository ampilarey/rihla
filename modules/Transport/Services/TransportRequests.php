<?php

namespace Modules\Transport\Services;

use App\Exceptions\DeskRefusal;
use App\Models\Customer;
use App\Models\Notice;
use App\Models\Partner;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Telegram\Services\Notifier;
use Modules\Transport\Models\TransportRequest;
use Modules\Transport\Models\TransportRequestEvent;

/**
 * Cargo and charter requests, from the form to delivered —
 * docs/TRANSPORT_PLAN.md (T4). Quoted each time: there is no rate card, and
 * an invented one would be wrong (decision 3).
 */
final class TransportRequests
{
    /**
     * @param  array{kind: string, origin_island_id: int, destination_island_id: int, wanted_on: string, contact_name: string, contact_phone: string, passengers?: int|null, cargo_description?: string|null, cargo_weight_kg?: int|null, notes?: string|null}  $data
     */
    public function submit(array $data, ?Customer $customer = null): TransportRequest
    {
        if (! array_key_exists($data['kind'], TransportRequest::KINDS)) {
            throw new DeskRefusal('Choose cargo or a charter.');
        }

        if ($data['origin_island_id'] === $data['destination_island_id']) {
            throw new DeskRefusal('Choose two different islands.');
        }

        if ($data['kind'] === TransportRequest::CARGO && blank($data['cargo_description'] ?? null)) {
            throw new DeskRefusal('Say what the cargo is.');
        }

        if ($data['kind'] === TransportRequest::CHARTER && (int) ($data['passengers'] ?? 0) < 1) {
            throw new DeskRefusal('Say how many people are travelling.');
        }

        $request = DB::transaction(function () use ($data, $customer): TransportRequest {
            $request = new TransportRequest;
            $request->forceFill([
                'reference' => 'pending-'.Str::random(16),
                'status_key' => Str::random(40),
                'kind' => $data['kind'],
                'status' => TransportRequest::REQUESTED,
                'customer_id' => $customer?->getKey(),
                'partner_id' => Partner::rihla()?->getKey(),
                'origin_island_id' => $data['origin_island_id'],
                'destination_island_id' => $data['destination_island_id'],
                'wanted_on' => $data['wanted_on'],
                'contact_name' => trim($data['contact_name']),
                'contact_phone' => trim($data['contact_phone']),
                'passengers' => $data['kind'] === TransportRequest::CHARTER ? (int) $data['passengers'] : null,
                'cargo_description' => $data['kind'] === TransportRequest::CARGO ? trim((string) $data['cargo_description']) : null,
                'cargo_weight_kg' => $data['kind'] === TransportRequest::CARGO ? ($data['cargo_weight_kg'] ?? null) : null,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'currency' => 'MVR',
            ])->save();
            $request->forceFill(['reference' => sprintf('RIH-R-%s-%05d', now()->format('Y'), $request->getKey())])->save();
            TransportRequestEvent::record($request, TransportRequest::REQUESTED, null);

            return $request;
        });

        $request->load(['origin', 'destination']);
        app(Notifier::class)->tell('transport.book', '📦 New '.strtolower(TransportRequest::KINDS[$request->kind]).' request '.$request->reference.': '
            .($request->origin->name ?? '?').' → '.($request->destination->name ?? '?').', '.$request->wanted_on->format('D j M')
            .'. '.$request->contact_name.', '.$request->contact_phone.'. It needs a quote.');

        return $request;
    }

    /**
     * Price it. A request already quoted can be quoted again until it is
     * paid; the asker is put on the notice queue to be sent the price.
     */
    public function quote(TransportRequest $request, Money $price, ?string $note, ?User $by): void
    {
        if (! in_array($request->status, TransportRequest::OPEN, true)) {
            throw new DeskRefusal('Only a request that is not yet paid can be quoted.');
        }

        if ($price->minor < 1) {
            throw new DeskRefusal('A quote must be more than nothing.');
        }

        if ($price->currency !== $request->currency) {
            throw new DeskRefusal('Quote in '.$request->currency.'.');
        }

        $request->forceFill([
            'status' => TransportRequest::QUOTED,
            'quote_minor' => $price->minor,
            'quote_note' => filled($note) ? trim((string) $note) : null,
            'quoted_at' => now(),
            'quoted_by' => $by?->getKey(),
        ])->save();
        TransportRequestEvent::record($request, TransportRequest::QUOTED, $by, $price->format().(filled($note) ? ' — '.trim((string) $note) : ''));

        // Already paid enough (a re-quote below what arrived): it is paid.
        if ($request->paid_minor >= $price->minor) {
            $request->storePaidTotal($request->paid_minor);
        }

        $request->load(['origin', 'destination']);
        Notice::raise(
            $request,
            Notice::TRANSPORT_QUOTED,
            'Your '.strtolower(TransportRequest::KINDS[$request->kind]).' '.($request->origin->name ?? '').' → '.($request->destination->name ?? '').' is quoted at '.$price->format(),
            (filled($note) ? trim((string) $note)."\n\n" : '').'Pay by bank transfer quoting '.$request->reference.', or in cash at our office. Your request: '
                .route('transport.requests.show', ['locale' => 'en', 'key' => $request->status_key]),
        );
    }

    /** Move a paid request one step on: received, loaded, delivered; or completed. */
    public function advance(TransportRequest $request, ?User $by, ?string $note = null): string
    {
        $next = $request->nextStep();

        if ($next === null) {
            throw new DeskRefusal(in_array($request->status, TransportRequest::OPEN, true)
                ? 'It has to be paid before it can move on.'
                : 'There is no step after '.strtolower(TransportRequest::STATUSES[$request->status] ?? $request->status).'.');
        }

        $request->forceFill(['status' => $next])->save();
        TransportRequestEvent::record($request, $next, $by, $note);

        return $next;
    }

    /** Cancel an unpaid request, or decline it, with a reason the asker sees. */
    public function cancel(TransportRequest $request, string $reason, ?User $by): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DeskRefusal('Say why: the asker will see it.');
        }

        if (! in_array($request->status, TransportRequest::OPEN, true)) {
            throw new DeskRefusal('A paid request is not cancelled here: refund the payment first, then cancel.');
        }

        if ($request->paid_minor > 0) {
            throw new DeskRefusal('Money has arrived for it: refund the payment first, then cancel.');
        }

        $request->forceFill(['status' => TransportRequest::CANCELLED, 'cancelled_reason' => $reason])->save();
        TransportRequestEvent::record($request, TransportRequest::CANCELLED, $by, $reason);
    }
}
