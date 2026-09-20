<?php

namespace App\Http\Middleware;

use App\Services\Portal\Gatekeeper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only somebody holding a live portal session sees a portal page.
 *
 * The booking is put on the request so no controller has to look it up, and
 * so none of them can accidentally read a booking id out of the URL — which
 * is the mistake this whole design exists to prevent. Nothing in the portal
 * takes an identifier from the path: the session says which booking, and a
 * visitor who guesses a URL gets their own booking or nothing.
 *
 * **A portal session is not enough to pull a document file.** Passport
 * scans and transfer slips stay behind `document.download` and
 * `payment.download`, which only staff hold. The portal shows that a
 * passport is on file and whether it has been checked; it does not hand the
 * image back. A link sent over WhatsApp will be forwarded into a family
 * group chat, and the difference between "your passport is verified" and a
 * passport scan appearing in that chat is the whole point.
 */
class PortalSession
{
    public function __construct(private readonly Gatekeeper $gatekeeper) {}

    public function handle(Request $request, Closure $next): Response
    {
        $booking = $this->gatekeeper->booking();

        if ($booking === null) {
            // No explicit locale: SetLocale has already put it in
            // URL::defaults, which is why controllers never receive the
            // {locale} parameter either.
            return redirect()->route('portal.locked');
        }

        $request->attributes->set('portal_booking', $booking);

        return $next($request);
    }
}
