<?php

namespace App\Http\Controllers;

use App\Models\Departure;
use App\Models\Package;
use App\Models\WaitlistEntry;
use App\Services\Booking\Waitlist;
use App\Support\Checkout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Joining the queue for a full departure, and claiming a seat that came back.
 *
 * A sold-out departure is otherwise a dead end: the page says "Fully booked"
 * and the visitor leaves. Seats do come back — a hold lapses, a passport
 * turns out to be expired, a family cancels — and today nobody is told.
 */
class WaitlistController extends Controller
{
    public function __construct(private readonly Waitlist $waitlist) {}

    public function join(string $slug, Request $request): RedirectResponse
    {
        $package = Package::published()->where('slug', $slug)
            ->with(['publishedDepartures'])
            ->firstOrFail();

        $validated = $request->validate([
            'departure' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'seats' => ['required', 'integer', 'min:1', 'max:'.(int) config('booking.party.max', 12)],
        ]);

        /** @var Departure|null $departure */
        $departure = $package->publishedDepartures->find($validated['departure']);

        if ($departure === null) {
            return back()->withInput()->withErrors([
                'departure' => __('messages.That departure is not open for booking.'),
            ]);
        }

        $this->waitlist->joinByContact(
            $departure,
            $validated['name'],
            $validated['phone'],
            $validated['email'] ?? null,
            (int) $validated['seats'],
        );

        // An explicit route rather than back(): back() falls through to the
        // previous URL, and when there is no referer — a form posted from a
        // shared link, a browser that strips it — that is the bare domain,
        // which redirects again to pick a language. The flash is consumed by
        // that intermediate request and the visitor lands on the homepage
        // having been told nothing.
        return redirect()
            ->route('packages.show', $package->slug)
            ->with('status', __('messages.You are on the waiting list. We will message you if a seat comes back.'));
    }

    /**
     * A promoted party claiming the seats held for them.
     *
     * The route is signed and expires with the offer, because it hands over
     * seats that are already held — a guessable URL would let anybody take
     * somebody else's. The `signed` middleware enforces that; this method
     * still checks the offer is live, because a link can be opened after the
     * seats have gone back.
     */
    public function claim(WaitlistEntry $entry): RedirectResponse
    {
        if (! $entry->offerIsLive()) {
            return redirect()
                ->route('packages.show', $entry->departure->package->slug)
                ->with('status', __('messages.That offer has expired. Join the waiting list again and we will keep looking.'));
        }

        // Drop straight into the checkout at the travellers step: the seats
        // are already held, so there is nothing to choose.
        Checkout::remember($entry->seatHold, $entry->occupancy ?? $this->firstOccupancy($entry->departure));
        Checkout::rememberWaitlistEntry($entry->getKey());

        return redirect()->route('booking.travellers');
    }

    /** The first room type this departure prices; staff can move them later. */
    private function firstOccupancy(Departure $departure): string
    {
        return $departure->occupanciesOffered()[0] ?? 'quad';
    }
}
