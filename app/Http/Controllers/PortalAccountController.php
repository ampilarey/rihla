<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\Invoices\Documents;
use App\Services\Portal\Gatekeeper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer portal with a password (companies plan, Phase 3h, from
 * Hijaz): signing in with a mobile number, choosing a password from a
 * booking link, and moving between one's own bookings.
 *
 * Nothing here takes a customer from the URL, and a booking chosen by id is
 * checked against the signed-in customer before the session moves to it —
 * the portal's rule that guessing an address shows your own booking or
 * nothing holds here too.
 */
class PortalAccountController extends Controller
{
    public function __construct(private readonly Gatekeeper $gatekeeper) {}

    public function showLogin(): View|RedirectResponse
    {
        if ($this->gatekeeper->signedInCustomer() !== null) {
            return redirect()->route('portal.bookings');
        }

        return view('portal.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $customer = $this->gatekeeper->attempt($data['phone'], $data['password']);

        if ($customer === null) {
            return back()->withInput($request->only('phone'))
                ->withErrors(['phone' => __('messages.That number and password do not match. Use the link we sent, or message us.')]);
        }

        $this->gatekeeper->admitCustomer($customer);

        return redirect()->route('portal.bookings');
    }

    /** Every booking of the signed-in customer; a link session has only its own. */
    public function bookings(Request $request): View
    {
        $customer = $this->gatekeeper->signedInCustomer();
        /** @var Booking $current */
        $current = $request->attributes->get('portal_booking');

        return view('portal.bookings', [
            'customer' => $customer ?? $current->customer,
            'bookings' => $customer === null ? collect([$current]) : $this->gatekeeper->bookingsOf($customer),
            'current' => $current,
            'signedIn' => $customer !== null,
        ]);
    }

    public function choose(Request $request): RedirectResponse
    {
        $booking = Booking::query()->find((int) $request->input('booking'));

        if ($booking === null || ! $this->gatekeeper->choose($booking)) {
            return redirect()->route('portal.bookings');
        }

        return redirect()->route('portal.home');
    }

    /**
     * The travel letters for the open booking (Phase 3h). Without passport
     * numbers: a portal link is the kind of thing that gets forwarded, and
     * the office prints the copy that carries them.
     */
    public function letters(Request $request, Documents $documents): Response
    {
        /** @var Booking $booking */
        $booking = $request->attributes->get('portal_booking');

        abort_unless(in_array($booking->status, [Booking::CONFIRMED, Booking::COMPLETED], true), 404);

        return $documents->travelLetters($booking, withPassport: false)->download('travel-letters-'.strtolower((string) $booking->reference).'.pdf');
    }

    /** Choose a password from a link session: once, and with the number on file. */
    public function choosePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        /** @var Booking $booking */
        $booking = $request->attributes->get('portal_booking');

        if (! $this->gatekeeper->choosePassword($booking, $data['phone'], $data['password'])) {
            return back()->withErrors(['phone' => __('messages.That is not the number we have for you, or a password is already set. Message us and we will help.')]);
        }

        return redirect()->route('portal.bookings')->with('portal_notice', __('messages.Your password is set. Next time, sign in with your mobile number.'));
    }
}
