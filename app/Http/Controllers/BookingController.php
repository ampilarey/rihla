<?php

namespace App\Http\Controllers;

use App\Exceptions\NoSeatsAvailable;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\Setting;
use App\Models\Traveller;
use App\Models\WaitlistEntry;
use App\Services\Booking\SeatAllocator;
use App\Services\Booking\Waitlist;
use App\Support\Checkout;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The public booking flow: package → departure → occupancy → who is
 * travelling → review → seats held.
 *
 * Server-rendered, one page per step, a POST between each. No JavaScript is
 * required to complete a booking — this traffic is a phone on mobile data in
 * Malé, and a checkout that needs a bundle to load is a checkout that fails
 * for the people most likely to be booking.
 *
 * ## Where it stops, and why
 *
 * At the seat hold. Taking money needs a BML Connect merchant account that
 * does not exist yet (plan §5.3), and building a payment step against
 * credentials nobody can test with would produce a screen that looks
 * finished and has never once worked.
 *
 * So the flow ends where it honestly can: the seats are held for the
 * configured window, the booking has a reference, and the customer is told
 * to message the team to complete it. That is what happens today anyway —
 * every Rihla booking is a WhatsApp conversation — except that now it
 * arrives with the travellers' details already entered and the seat already
 * off the departure, instead of as "how much for 4 people in March?".
 *
 * The window is the plan's fifteen minutes. It is short for a flow that ends
 * in a WhatsApp message, and the page says so plainly rather than implying a
 * deadline nobody set: **how long a booking may sit unpaid is a policy
 * decision the owner has not made**, and it is not this controller's to
 * invent. Once staff can extend a hold from the admin, that becomes their
 * call on each booking.
 */
class BookingController extends Controller
{
    public function __construct(private readonly SeatAllocator $seats) {}

    /**
     * Step 1 — which departure, which room, how many people.
     *
     * No `$locale` parameter, even though the route is prefixed with one:
     * SetLocale consumes it before the controller runs.
     */
    public function start(string $slug, Request $request): View
    {
        $package = $this->package($slug);

        return view('booking.start', [
            'package' => $package,
            'departures' => $package->publishedDepartures,
            'selected' => $this->preselectedDeparture($package, $request),
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    /** Step 1 submitted: take the seats off the departure and start the clock. */
    public function hold(string $slug, Request $request): RedirectResponse
    {
        $package = $this->package($slug);

        $validated = $request->validate([
            'departure' => ['required', 'integer'],
            'occupancy' => ['required', 'string', 'in:'.implode(',', PriceTier::OCCUPANCIES)],
            'seats' => ['required', 'integer', 'min:1', 'max:'.(int) config('booking.party.max', 12)],
        ]);

        /** @var Departure|null $departure */
        $departure = $package->publishedDepartures->find($validated['departure']);

        if ($departure === null || ! $departure->is_bookable) {
            return back()->withInput()->withErrors([
                'departure' => __('messages.That departure is not open for booking.'),
            ]);
        }

        if ($departure->tierFor($validated['occupancy']) === null) {
            return back()->withInput()->withErrors([
                'occupancy' => __('messages.This departure does not offer that room type.'),
            ]);
        }

        try {
            // No booking yet: there is no customer to attach one to until the
            // next step, and `seat_holds.booking_id` is nullable for exactly
            // this. The seats come off now because that is the moment the
            // visitor committed to them — holding after the forms are filled
            // in would mean losing the seat after ten minutes of typing.
            $hold = $this->seats->hold($departure, (int) $validated['seats']);
        } catch (NoSeatsAvailable) {
            return back()->withInput()->withErrors(['seats' => $this->seatsGoneMessage($departure)]);
        }

        Checkout::remember($hold, $validated['occupancy']);

        return redirect()->route('booking.travellers');
    }

    /** Step 2 — who is going. */
    public function travellers(): View|RedirectResponse
    {
        if (($hold = Checkout::hold()) === null) {
            return $this->holdLapsed();
        }

        return view('booking.travellers', [
            'hold' => $hold,
            'departure' => $hold->departure,
            'package' => $hold->departure->package,
            'occupancy' => Checkout::occupancy(),
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    /**
     * Step 2 submitted: the customer, the travellers, the booking and its
     * lines, all in one transaction.
     */
    public function storeTravellers(Request $request): RedirectResponse
    {
        if (($hold = Checkout::hold()) === null) {
            return $this->holdLapsed();
        }

        $occupancy = Checkout::occupancy() ?? PriceTier::OCCUPANCIES[0];
        $departure = $hold->departure;

        $validated = $request->validate([
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'travellers' => ['required', 'array', 'size:'.$hold->seats],
            'travellers.*.full_name' => ['required', 'string', 'max:255'],
            'travellers.*.date_of_birth' => ['nullable', 'date', 'before:today'],
            'travellers.*.gender' => ['nullable', 'in:'.implode(',', Traveller::GENDERS)],
            'travellers.*.passport_number' => ['nullable', 'string', 'max:40'],
            'travellers.*.passport_expiry' => ['nullable', 'date'],
        ]);

        if (($complaint = $this->partyIsTravellable($validated['travellers'], $departure)) !== null) {
            return back()->withInput()->withErrors(['travellers' => $complaint]);
        }

        $booking = DB::transaction(function () use ($validated, $hold, $departure, $occupancy): Booking {
            $customer = Customer::create([
                'name' => $validated['contact_name'],
                'phone' => $validated['contact_phone'],
                'email' => $validated['contact_email'] ?? null,
            ]);

            $booking = Booking::create([
                'customer_id' => $customer->getKey(),
                'departure_id' => $departure->getKey(),
                'seats' => $hold->seats,
                'currency' => $departure->lead_price->currency ?? 'MVR',
            ]);

            foreach (array_values($validated['travellers']) as $index => $details) {
                $this->addTraveller($booking, $customer, $departure, $occupancy, $details, isLead: $index === 0);
            }

            $booking->recalculateTotal();

            // The hold was anonymous until now. Attaching it is what makes
            // the seats belong to this booking, so that expiry can expire the
            // booking too rather than leaving it claiming seats it lost.
            $hold->forceFill(['booking_id' => $booking->getKey()])->save();

            return $booking;
        });

        Checkout::attach($booking);

        // Entered from a waiting-list offer: the entry has now become a
        // booking and must stop showing as somebody still waiting.
        $entryId = Checkout::waitlistEntryId();

        if ($entryId !== null) {
            $entry = WaitlistEntry::find($entryId);

            if ($entry instanceof WaitlistEntry) {
                app(Waitlist::class)->convert($entry, $booking->getKey());
            }
        }

        return redirect()->route('booking.review');
    }

    /** Step 3 — read it back before committing. */
    public function review(): View|RedirectResponse
    {
        if (($booking = Checkout::booking()) === null || Checkout::hold() === null) {
            return $this->holdLapsed();
        }

        return view('booking.review', [
            'booking' => $booking,
            'hold' => Checkout::hold(),
            'departure' => $booking->departure,
            'package' => $booking->departure->package,
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    /** Step 3 submitted. */
    public function confirm(Request $request): RedirectResponse
    {
        if (($booking = Checkout::booking()) === null || Checkout::hold() === null) {
            return $this->holdLapsed();
        }

        // Not terms and conditions: Rihla has not published any, and
        // inventing a contract on their behalf would be worse than having
        // none. This is a confirmation of fact — that the details are right
        // and that payment is arranged with a person.
        $request->validate([
            'confirmed' => ['accepted'],
        ], [
            'confirmed.accepted' => __('messages.Please confirm the details are correct before continuing.'),
        ]);

        if ($booking->status === Booking::DRAFT) {
            $booking->transitionTo(Booking::HELD, 'Confirmed by the customer in checkout.');
        }

        return redirect()->route('booking.confirmation');
    }

    /**
     * The last page: the reference to quote, and what happens next.
     *
     * Readable after the hold lapses — deliberately. Somebody who comes back
     * to the tab an hour later needs to see their reference and how to reach
     * Rihla, not a redirect that loses it.
     */
    public function confirmation(): View|RedirectResponse
    {
        if (($booking = Checkout::booking()) === null) {
            return $this->holdLapsed();
        }

        return view('booking.confirmation', [
            'booking' => $booking,
            'hold' => Checkout::hold(),
            'departure' => $booking->departure,
            'package' => $booking->departure->package,
            'socialSettings' => Setting::getSocialSettings(),
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function package(string $slug): Package
    {
        return Package::published()
            ->where('slug', $slug)
            ->with([
                'publishedDepartures' => fn ($query) => $query->upcoming()->with(['priceTiers', 'hotels']),
            ])
            ->firstOrFail();
    }

    private function preselectedDeparture(Package $package, Request $request): ?Departure
    {
        $id = (int) $request->query('departure');

        return $id > 0 ? $package->publishedDepartures->find($id) : null;
    }

    /**
     * One traveller, at the price their age and room actually cost.
     *
     * The tier is looked up for their traveller type and falls back to the
     * adult tier when the departure does not publish one — a departure that
     * prices only adults prices everybody as an adult rather than having a
     * child discount invented for it.
     *
     * @param  array<string, mixed>  $details
     */
    private function addTraveller(
        Booking $booking,
        Customer $customer,
        Departure $departure,
        string $occupancy,
        array $details,
        bool $isLead,
    ): void {
        $traveller = Traveller::create([
            'customer_id' => $customer->getKey(),
            'full_name' => $details['full_name'],
            'date_of_birth' => $details['date_of_birth'] ?? null,
            'gender' => $details['gender'] ?? null,
            'passport_number' => $details['passport_number'] ?? null,
            'passport_expiry' => $details['passport_expiry'] ?? null,
        ]);

        $paxType = PriceTier::paxTypeForAge($traveller->ageOn($departure->date_start));
        $tier = $departure->tierFor($occupancy, $paxType) ?? $departure->tierFor($occupancy);

        $line = $booking->travellers()->create([
            'traveller_id' => $traveller->getKey(),
            'occupancy' => $occupancy,
            'pax_type' => $tier->pax_type ?? PriceTier::ADULT,
            'price_tier_id' => $tier?->getKey(),
            'amount_minor' => $tier->amount_minor ?? 0,
            'is_lead' => $isLead,
        ]);

        $booking->lines()->create([
            'booking_traveller_id' => $line->getKey(),
            'type' => BookingLine::SEAT,
            'description' => $traveller->full_name,
            'unit_amount_minor' => $line->amount_minor,
            'amount_minor' => $line->amount_minor,
            'currency' => $booking->currency,
        ]);
    }

    /**
     * The one party rule this application can state on its own.
     *
     * A child cannot travel on a booking with no adult on it. That is
     * mechanical and needs nobody's policy.
     *
     * The mahram requirement is deliberately **not** here. It is a Saudi
     * rule with real nuance, it changes, and the plan (§5.4b) is explicit
     * that every Saudi requirement lives in versioned configuration rather
     * than in code. Encoding a half-remembered version of it in a checkout
     * would turn away bookings that are perfectly allowed — and quietly
     * accept ones that are not.
     *
     * @param  array<int, array<string, mixed>>  $travellers
     */
    private function partyIsTravellable(array $travellers, Departure $departure): ?string
    {
        $adults = 0;

        foreach ($travellers as $details) {
            $age = isset($details['date_of_birth'])
                ? (int) Carbon::parse($details['date_of_birth'])->diffInYears($departure->date_start)
                : null;

            if (PriceTier::paxTypeForAge($age) === PriceTier::ADULT) {
                $adults++;
            }
        }

        return $adults > 0
            ? null
            : __('messages.At least one adult must travel with the party.');
    }

    /**
     * `->` rather than `?->` on the left of `??` throughout this class, and
     * on purpose: `??` already suppresses the null access, so the nullsafe
     * operator adds nothing and static analysis says so. `$tier` and
     * `fresh()` really can be null; the coalesce is what handles it.
     */
    private function seatsGoneMessage(Departure $departure): string
    {
        return $departure->has_capacity
            ? __('messages.Those seats have just gone. :count are left on this departure.', [
                'count' => $departure->fresh()->seats_remaining ?? 0,
            ])
            : __('messages.Booking is not open for this departure yet. Message us and we will arrange it.');
    }

    private function holdLapsed(): RedirectResponse
    {
        Checkout::clear();

        return redirect()->route('packages.index')->with(
            'status',
            __('messages.Your seats were released because the booking was not completed in time. Please start again.'),
        );
    }
}
