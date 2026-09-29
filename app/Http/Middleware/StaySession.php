<?php

namespace App\Http\Middleware;

use App\Services\Stays\StayGatekeeper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate in front of `/my-stay` — §16.7. The same shape as
 * {@see PortalSession}, with its own session and its own locked page.
 */
class StaySession
{
    public function __construct(private readonly StayGatekeeper $gatekeeper) {}

    public function handle(Request $request, Closure $next): Response
    {
        $stay = $this->gatekeeper->stay();

        if ($stay === null) {
            return redirect()->route('my-stay.locked');
        }

        $request->attributes->set('my_stay', $stay);

        return $next($request);
    }
}
