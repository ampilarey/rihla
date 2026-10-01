<?php

namespace App\Http\Controllers;

use App\Exceptions\CommissionNotSet;
use App\Exceptions\NotSoldToAudience;
use App\Exceptions\RoomNotAvailable;
use App\Models\Customer;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Stays\Availability;
use App\Services\Stays\Commission;
use App\Services\Stays\GreenTax;
use App\Services\Stays\StayAddons;
use App\Services\Stays\StayBooking;
use App\Services\Stays\StayGatekeeper;
use App\Support\Audience;
use App\Support\Services as ServiceRegistry;
use App\Support\StayFilters;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A guest books a room themselves — §16.7, §16 Phase 13.3.
 *
 * Two requests, both server-rendered and working with no JavaScript, for
 * the reason §5.2 gives (a phone on mobile data):
 *
 * 1. `GET /stays/{property}/book` restates the quote, prints the policy
 *    the stay will be held to, says what is paid now, and takes the
 *    guest's details.
 * 2. `POST` makes the stay through {@see StayBooking::request()} — the same
 *    path staff and hosts use — and opens the guest's own page.
 *
 * An instant-book listing is held at once and goes to payment; any other
 * becomes a request, and nothing is paid until the host says yes.
 *
 * ## Who the guest is decides the price
 *
 * The audience chosen on the listing is a guess; nationality is the answer.
 * A mismatch re-quotes and says so rather than booking a Maldivian at the
 * tourist price or a visitor at the local one — the check-in desk checks
 * the passport against the register (§16.3 decision 6), and finding out
 * there is the worst place to find out.
 */
class StayCheckoutController extends Controller
{
    public function __construct(
        private readonly Availability $availability,
        private readonly GreenTax $greenTax,
        private readonly StayBooking $booking,
        private readonly StayGatekeeper $gatekeeper,
        private readonly StayAddons $addons,
    ) {}

    public function start(Request $request, Property $property): View|RedirectResponse
    {
        $this->assertBookable($property);

        $filters = StayFilters::fromRequest($request);
        $room = $this->room($property, $request->query('room'));
        $adults = $this->count($request->query('adults', $filters->guests ?? 1), 1);
        $children = $this->count($request->query('children', 0), 0);

        if (! $filters->hasDates()) {
            return $this->backToListing($property, $filters, __('messages.Choose your dates first, then pick a room.'));
        }

        if ($adults + $children > $room->sleeps) {
            return $this->backToListing($property, $filters, __('messages.That room sleeps :count. Choose a larger room or book two.', ['count' => $room->sleeps]));
        }

        try {
            $quote = $this->availability->quote($room, $filters->checkIn, $filters->checkOut, $filters->audience);
            $this->availability->assertAvailable($room, $filters->checkIn, $filters->checkOut);
        } catch (NotSoldToAudience) {
            return $this->backToListing($property, $filters, __('messages.That room has no price for these dates at local rates.'));
        } catch (RoomNotAvailable $refusal) {
            return $this->backToListing($property, $filters, $refusal->getMessage());
        }

        $guests = $adults + $children;

        return view('stays.book', [
            'property' => $property,
            'room' => $room,
            'filters' => $filters,
            'adults' => $adults,
            'children' => $children,
            'quote' => $quote,
            'deposit' => $quote->deposit($property->deposit_pct),
            'instant' => $property->isInstantBookable(),
            'greenTaxApplies' => $this->greenTax->appliesTo($filters->audience),
            'greenTaxAtProperty' => $this->greenTax->isCollectedAtProperty($property),
            'greenTaxEstimate' => $this->greenTax->forParty($guests, $quote->nights()),
            'addons' => StayAddons::offered($property->addons()->where('is_active', true)->with('property')->get(), $filters->audience),
            'guests' => $guests,
        ]);
    }

    public function store(Request $request, Property $property): RedirectResponse
    {
        $this->assertBookable($property);

        // The field no human sees. A bot is thanked and nothing is made —
        // the enquiry form's rule, for the enquiry form's reasons.
        if (filled($request->input('website'))) {
            return redirect()->route('stays.show', ['property' => $property->slug])
                ->with('status', __('messages.Thank you — we have your request.'));
        }

        $validated = $request->validate([
            'room' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'after:from', 'before_or_equal:'.CarbonImmutable::parse((string) $request->input('from', 'today'))->addDays(StayFilters::MAX_NIGHTS)->toDateString()],
            'adults' => ['required', 'integer', 'min:1', 'max:30'],
            'children' => ['nullable', 'integer', 'min:0', 'max:30'],
            'audience' => ['required', 'in:'.implode(',', Audience::ALL)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'citizenship' => ['required', 'in:maldivian,other'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'addons' => ['nullable', 'array', 'max:50'],
            'addons.*' => ['integer'],
            'accept' => ['accepted'],
        ], [
            'accept.accepted' => __('messages.Please confirm you have read the payment and cancellation terms.'),
        ]);

        $room = $this->room($property, $validated['room']);
        $audience = $validated['citizenship'] === 'maldivian' ? Audience::LOCAL : Audience::TOURIST;

        $query = [
            'property' => $property->slug,
            'room' => $room->getKey(),
            'from' => $validated['from'],
            'to' => $validated['to'],
            'adults' => (int) $validated['adults'],
            'children' => (int) ($validated['children'] ?? 0),
        ];

        // Nationality is the answer; the toggle on the listing was a guess.
        if ($audience !== $validated['audience']) {
            return redirect()->route('stays.book', $query + ['audience' => $audience])
                ->withInput($request->except('audience'))
                ->with('status', $audience === Audience::LOCAL
                    ? __('messages.As a Maldivian guest you pay the local price. We have re-quoted it below — please check it and confirm again.')
                    : __('messages.As a visitor you pay the tourist price. We have re-quoted it below — please check it and confirm again.'));
        }

        if ((int) $validated['adults'] + (int) ($validated['children'] ?? 0) > $room->sleeps) {
            return redirect()->route('stays.book', $query + ['audience' => $audience])->withInput()
                ->withErrors(['adults' => __('messages.That room sleeps :count. Choose a larger room or book two.', ['count' => $room->sleeps])]);
        }

        // A new customer each time, as the Umrah booking flow does. Matching
        // on a typed e-mail address would attach this stay to whoever owns
        // that address — a stranger's booking in somebody else's history —
        // so duplicates are left to the office's merge tools, where a
        // person decides. Made in the same transaction as the stay, so a
        // refusal leaves no customer behind with nothing booked.
        try {
            $stay = DB::transaction(fn (): Stay => $this->withAddons($this->booking->request(
                Customer::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                ]),
                $room,
                CarbonImmutable::parse($validated['from']),
                CarbonImmutable::parse($validated['to']),
                adults: (int) $validated['adults'],
                children: (int) ($validated['children'] ?? 0),
                details: [
                    'special_requests' => $validated['special_requests'] ?? null,
                    'source' => Commission::MARKETPLACE,
                    'created_via' => Stay::VIA_GUEST,
                ],
                audience: $audience,
            ), $validated['addons'] ?? []));
        } catch (RoomNotAvailable $refusal) {
            return redirect()->route('stays.show', ['property' => $property->slug, 'from' => $validated['from'], 'to' => $validated['to']])
                ->with('status', $refusal->getMessage());
        } catch (NotSoldToAudience) {
            return redirect()->route('stays.show', ['property' => $property->slug])
                ->with('status', __('messages.That room has no price for these dates at local rates.'));
        } catch (CommissionNotSet $unset) {
            // Rihla's to fix, not the guest's: the office is told through
            // the error log, and the guest is sent to a person rather than
            // shown an error.
            report($unset);

            return redirect()->route('stays.show', ['property' => $property->slug])
                ->with('status', __('messages.This place cannot be booked online just yet. Message us and we will book it for you.'));
        }

        $token = $this->gatekeeper->issue($stay);
        $this->gatekeeper->open($stay->getKey());

        return redirect()->route('my-stay.home')->with('stay_link', route('my-stay.enter', ['token' => $token]));
    }

    /**
     * The add-ons the guest ticked, onto the new stay's bill — inside the
     * booking's transaction, so a refusal leaves no stray lines behind.
     *
     * @param  array<int, mixed>  $ids
     */
    private function withAddons(Stay $stay, array $ids): Stay
    {
        $this->addons->attach($stay, $ids);

        return $stay;
    }

    /**
     * Listable, and behind a door that is on. `coming_soon` shows the
     * pages and takes no money (§15.2 decision 6), so it books nothing.
     */
    private function assertBookable(Property $property): void
    {
        $service = $property->type === Property::RENTAL ? 'stays_rooms' : 'stays_guesthouses';

        if (! $property->isListable() || ! ServiceRegistry::isOn($service)) {
            throw new NotFoundHttpException;
        }
    }

    /** A room of *this* property — never one named by id from another. */
    private function room(Property $property, mixed $id): RoomType
    {
        $room = is_numeric($id) ? $property->roomTypes()->whereKey((int) $id)->first() : null;

        if (! $room instanceof RoomType) {
            throw new NotFoundHttpException;
        }

        return $room;
    }

    private function count(mixed $value, int $floor): int
    {
        return is_numeric($value) ? max($floor, min(30, (int) $value)) : $floor;
    }

    private function backToListing(Property $property, StayFilters $filters, string $message): RedirectResponse
    {
        return redirect()->route('stays.show', ['property' => $property->slug] + $filters->toQuery())
            ->with('status', $message);
    }
}
