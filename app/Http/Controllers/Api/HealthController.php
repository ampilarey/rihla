<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DeployedCommit;
use App\Support\DeploySecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Is this host up, and what is it running?
 *
 * The answer for anonymous callers is byte-for-byte what it has always been.
 * The deploy pipeline, which holds the shared secret, additionally gets the
 * commit that is serving — the one thing the old smoke check could not tell
 * it, and the reason a failed deploy could report green.
 *
 * The commit is behind the secret because this endpoint is public and
 * unauthenticated. The repository is public today, so the SHA gives nothing
 * away; if it is ever made private, naming the exact revision in production
 * tells an attacker which known advisories apply. The gate costs nothing now
 * and means that change is not a disclosure.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = [
            'ok' => true,
            'app' => config('app.name'),
            'env' => config('app.env'),
        ];

        if (DeploySecret::matches($request)) {
            $payload['commit'] = DeployedCommit::sha();
        }

        return response()->json($payload);
    }
}
