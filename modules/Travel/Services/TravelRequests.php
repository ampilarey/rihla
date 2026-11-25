<?php

namespace Modules\Travel\Services;

use App\Exceptions\DeskRefusal;
use App\Models\Customer;
use App\Models\Notice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Telegram\Services\Notifier;
use Modules\Travel\Models\TravelQuoteOption;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Models\TravelRequestEvent;

/**
 * "Plan my trip", from the form to paid — docs/TRAVEL_AGENCY_PLAN.md (A1).
 * Staff quote options because no supplier account exists to book live
 * (decision 1); the customer picks one before it expires.
 */
final class TravelRequests
{
    /** How long a quote holds by default: fares move. */
    public const QUOTE_DAYS = 3;

    /**
     * @param  array{wants_flight: bool, wants_hotel: bool, wants_visa: bool, origin?: string|null, destination: string, depart_on: string, return_on?: string|null, adults: int, children?: int, contact_name: string, contact_phone: string, contact_email?: string|null, notes?: string|null}  $data
     */
    public function submit(array $data, ?Customer $customer = null): TravelRequest
    {
        if (! $data['wants_flight'] && ! $data['wants_hotel'] && ! $data['wants_visa']) {
            throw new DeskRefusal('Choose a flight, a hotel, a visa, or any mix of them.');
        }

        if ($data['adults'] < 1) {
            throw new DeskRefusal('At least one adult travels.');
        }

        $request = DB::transaction(function () use ($data, $customer): TravelRequest {
            $request = new TravelRequest;
            $request->forceFill([
                'reference' => 'pending-'.Str::random(16),
                'status_key' => Str::random(40),
                'status' => TravelRequest::REQUESTED,
                'customer_id' => $customer?->getKey(),
                'wants_flight' => $data['wants_flight'],
                'wants_hotel' => $data['wants_hotel'],
                'wants_visa' => $data['wants_visa'],
                'origin' => filled($data['origin'] ?? null) ? trim((string) $data['origin']) : null,
                'destination' => trim($data['destination']),
                'depart_on' => $data['depart_on'],
                'return_on' => $data['return_on'] ?? null,
                'adults' => $data['adults'],
                'children' => $data['children'] ?? 0,
                'contact_name' => trim($data['contact_name']),
                'contact_phone' => trim($data['contact_phone']),
                'contact_email' => filled($data['contact_email'] ?? null) ? Str::lower(trim((string) $data['contact_email'])) : null,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'currency' => 'MVR',
            ])->save();
            $request->forceFill(['reference' => sprintf('RIH-V-%s-%05d', now()->format('Y'), $request->getKey())])->save();
            TravelRequestEvent::record($request, TravelRequest::REQUESTED, null);

            return $request;
        });

        app(Notifier::class)->tell('travel.quote', '✈️ New trip request '.$request->reference.': '.$request->wants().' to '.$request->destination
            .', '.$request->depart_on->format('j M').', '.($request->adults + $request->children).' people. '
            .$request->contact_name.', '.$request->contact_phone.'. It needs a quote.');

        return $request;
    }

    public function addOption(TravelRequest $request, string $title, ?string $details, Money $price): TravelQuoteOption
    {
        if (! in_array($request->status, TravelRequest::QUOTABLE, true)) {
            throw new DeskRefusal('Options can only be added before the customer accepts one.');
        }

        if (trim($title) === '' || $price->minor < 1) {
            throw new DeskRefusal('An option needs a name and a price.');
        }

        if ($price->currency !== $request->currency) {
            throw new DeskRefusal('Quote in '.$request->currency.'.');
        }

        $option = new TravelQuoteOption;
        $option->forceFill([
            'travel_request_id' => $request->getKey(),
            'title' => trim($title),
            'details' => filled($details) ? trim((string) $details) : null,
            'price_minor' => $price->minor,
            'currency' => $price->currency,
        ])->save();

        return $option;
    }

    public function removeOption(TravelQuoteOption $option): void
    {
        $request = $option->request()->firstOrFail();

        if (! in_array($request->status, TravelRequest::QUOTABLE, true)) {
            throw new DeskRefusal('The customer has accepted; the options are fixed now.');
        }

        $option->delete();
    }

    /** Send the options: the customer is queued to be told, and the page lets them choose. */
    public function sendQuote(TravelRequest $request, int $days, ?User $by): void
    {
        if (! in_array($request->status, TravelRequest::QUOTABLE, true)) {
            throw new DeskRefusal('Only a request the customer has not yet accepted can be quoted.');
        }

        if (! $request->options()->exists()) {
            throw new DeskRefusal('Add at least one option first.');
        }

        $days = max(1, min(30, $days));
        $request->forceFill([
            'status' => TravelRequest::QUOTED,
            'quoted_at' => now(),
            'quote_expires_at' => now()->addDays($days)->endOfDay(),
            'quoted_by' => $by?->getKey(),
        ])->save();

        $count = $request->options()->count();
        TravelRequestEvent::record($request, TravelRequest::QUOTED, $by, $count.' option(s), good until '.$request->quote_expires_at?->format('j M'));

        Notice::raise(
            $request,
            Notice::TRAVEL_QUOTED,
            'Your '.$request->wants().' to '.$request->destination.': '.$count.' option(s) ready',
            'Choose one by '.$request->quote_expires_at?->format('j M').'; prices can change after that. '
                .route('travel.requests.show', ['locale' => 'en', 'key' => $request->status_key]),
        );
    }

    /** The customer chooses an option, on their page, before the quote expires. */
    public function accept(TravelRequest $request, int $optionId): TravelQuoteOption
    {
        return DB::transaction(function () use ($request, $optionId): TravelQuoteOption {
            /** @var TravelRequest $locked */
            $locked = TravelRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TravelRequest::QUOTED) {
                throw new DeskRefusal($locked->status === TravelRequest::ACCEPTED || $locked->status === TravelRequest::PAID
                    ? 'You have already chosen an option.'
                    : 'There is nothing to choose yet.');
            }

            if (! $locked->quoteIsLive()) {
                throw new DeskRefusal('These prices have expired. Message us and we will check them again.');
            }

            /** @var TravelQuoteOption|null $option */
            $option = $locked->options()->whereKey($optionId)->first();

            if ($option === null) {
                throw new DeskRefusal('Choose one of the options on this page.');
            }

            $locked->forceFill([
                'status' => TravelRequest::ACCEPTED,
                'accepted_option_id' => $option->getKey(),
                'accepted_at' => now(),
                'total_minor' => $option->price_minor,
            ])->save();
            TravelRequestEvent::record($locked, TravelRequest::ACCEPTED, null, $option->title.' — '.$option->price()->format());
            $request->setRawAttributes($locked->getAttributes(), sync: true);

            // Money that arrived before they chose may already cover it.
            if ($locked->paid_minor >= $option->price_minor) {
                $locked->storePaidTotal($locked->paid_minor);
            }

            app(Notifier::class)->tell('travel.quote', '✅ '.$locked->reference.' accepted: '.$option->title.', '.$option->price()->format().'. Waiting for payment.');

            return $option;
        });
    }

    public function cancel(TravelRequest $request, string $reason, ?User $by): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DeskRefusal('Say why: the customer will see it.');
        }

        if (in_array($request->status, [TravelRequest::PAID, TravelRequest::CANCELLED], true) || $request->paid_minor > 0) {
            throw new DeskRefusal($request->status === TravelRequest::CANCELLED ? 'It is already cancelled.' : 'Money has arrived for it: refund the payment first, then cancel.');
        }

        $request->forceFill(['status' => TravelRequest::CANCELLED, 'cancelled_reason' => $reason])->save();
        TravelRequestEvent::record($request, TravelRequest::CANCELLED, $by, $reason);
    }
}
