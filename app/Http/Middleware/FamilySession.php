<?php

namespace App\Http\Middleware;

use App\Services\Family\Doorkeeper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only somebody holding a live family session sees a family page.
 *
 * The access is put on the request so no controller looks it up, and so
 * none of them can read an identifier out of the URL. Nothing in the Family
 * Portal takes one: the session says which link, the link says which
 * booking, and editing the address bar shows a visitor their own page or
 * nothing.
 *
 * The access is re-read from the database on every request, so a pilgrim
 * who turns a link off closes it on whoever is already looking. A control
 * you own that only takes effect at the next login is not one you own.
 */
class FamilySession
{
    public function __construct(private readonly Doorkeeper $doorkeeper) {}

    public function handle(Request $request, Closure $next): Response
    {
        $access = $this->doorkeeper->access();

        if ($access === null) {
            return redirect()->route('family.locked');
        }

        $request->attributes->set('family_access', $access);

        return $next($request);
    }
}
