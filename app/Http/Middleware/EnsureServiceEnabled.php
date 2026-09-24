<?php

namespace App\Http\Middleware;

use App\Support\Services;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gates a route by the service registry — §15.3 (Phase 8.1) of the upgrade
 * plan. Aliased as `service`, so a route declares its dependency inline:
 * `->middleware('service:stays_guesthouses')`.
 *
 * Deliberately does not render anything itself for the `coming_soon` state.
 * A guesthouse landing page and a rooms landing page will want to say
 * "coming soon" in their own words and their own layout, not share one
 * generic template — so this only decides whether the request may proceed,
 * and shares which of the two live states it proceeded under. The
 * controller and its view read `service_state` to decide whether to offer
 * booking or only an enquiry form; a controller that never checks it is a
 * `coming_soon` service that quietly takes real bookings, which is exactly
 * the failure this switch exists to prevent.
 */
class EnsureServiceEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $service): Response
    {
        $state = Services::state($service);

        if ($state === Services::OFF) {
            throw new NotFoundHttpException;
        }

        $request->attributes->set('service_state', $state);
        view()->share('serviceState', $state);

        return $next($request);
    }
}
